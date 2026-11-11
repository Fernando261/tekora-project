"""Private opt-in test harness: consumes a fresh synthetic PHPUnit fixture on stdin.
No database path, existing directory, environment file or deployment entry point.
"""
import base64
import json
import multiprocessing as mp
import os
from pathlib import Path
import secrets
import re
import sqlite3
import stat
import sys
import tempfile
import time
import unittest

CANDIDATES = ['likes','comments','posts','reminders','emotions','citas','profesionista_bloqueos','profesionista_disponibilidades','profesionistas']
PROTECTED = ['activity_notifications','cache','cache_locks','failed_jobs','inquiry_notifications','job_batches','jobs','migrations','password_reset_tokens','personal_access_tokens','provider_availability_settings','provider_blocked_dates','provider_profiles','provider_weekly_intervals','reservation_events','role_user','roles','service_categories','service_inquiries','service_inquiry_messages','service_inquiry_reads','service_reservations','service_reviews','services','sessions','users']
RESULTS = {}
FIXTURE = None

def q(s): return '"'+s.replace('"','""')+'"'
def identity(p):
    s=p.lstat()
    return (s.st_dev,s.st_ino,s.st_uid,stat.S_IFMT(s.st_mode))

class Lab:
    def __init__(self):
        self.root=Path(tempfile.mkdtemp(prefix='tekora-w62e-',dir='/tmp'))
        self.root.chmod(0o700)
        self.owner=identity(self.root); self.creator=os.getpid(); self.token=secrets.token_hex(32)
        self.files={}; self.connections=[]; self.children=[]
        self.events=[]
        self.create('owner.json').write_text(json.dumps({'token':self.token,'pid':os.getpid()}))
    def __getstate__(self):
        state=dict(self.__dict__);state["connections"]=[];state["children"]=[];return state
    def guard(self):
        if identity(self.root)!=self.owner or self.root.is_symlink() or self.root.stat().st_mode & 0o077: raise RuntimeError('Root identity mismatch')
        marker=self.root/'owner.json'
        if identity(marker)!=self.files['owner.json'] or marker.is_symlink(): raise RuntimeError('Marker identity mismatch')
        record=json.loads(marker.read_text())
        if record['token']!=self.token or record['pid']!=self.creator: raise RuntimeError('Owner token mismatch')
    def create(self,name):
        if name not in ['owner.json','primary.sqlite','backup.sqlite','restore.sqlite','tampered.sqlite']: raise RuntimeError('Not an internal resource name')
        if name!='owner.json': self.guard()
        p=self.root/name
        fd=os.open(p,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,0o600);os.close(fd)
        self.files[name]=identity(p)
        return p
    def inspect(self):
        self.guard()
        for p in self.root.iterdir():
            if p.is_symlink() or not p.is_file() or p.stat().st_mode & 0o077: raise RuntimeError('Unexpected type/permissions')
            if p.name not in self.files: raise RuntimeError('Unknown resource: '+p.name)
            if identity(p)!=self.files[p.name]: raise RuntimeError('Resource identity mismatch')
    def sidecars(self,name):
        # Only call immediately after an owned SQLite operation; no other process
        # receives this private root except the children registered by this lab.
        self.guard()
        for suffix in ['-wal','-shm','-journal']:
            p=self.root/(name+suffix)
            if p.is_symlink():raise RuntimeError('Unsafe sidecar link')
            if p.exists():
                if not p.is_file() or p.stat().st_uid!=os.getuid(): raise RuntimeError('Unsafe sidecar')
                if p.name in self.files and identity(p)!=self.files[p.name]: raise RuntimeError('Replaced sidecar')
                self.files[p.name]=identity(p)
    def connect(self,name='primary.sqlite',create=False):
        self.inspect()
        p=self.create(name) if create else self.root/name
        if name not in self.files or identity(p)!=self.files[name] or p.is_symlink(): raise RuntimeError('Unowned DB')
        c=sqlite3.connect(p,timeout=0.15,isolation_level=None)
        c.execute('PRAGMA foreign_keys=ON');c.execute('PRAGMA synchronous=FULL');c.execute('PRAGMA busy_timeout=150')
        self.sidecars(name)
        self.connections.append(c);self.events.append({'pid':os.getpid(),'connection':len(self.connections),'resource':name,'synchronous':c.execute('PRAGMA synchronous').fetchone()[0],'foreign_keys':c.execute('PRAGMA foreign_keys').fetchone()[0],'busy_timeout_ms':c.execute('PRAGMA busy_timeout').fetchone()[0]})
        return c
    def cleanup(self):
        for child in self.children:
            if child.is_alive(): raise RuntimeError('Child still alive; stop cleanup')
        self.inspect() # validate every resource BEFORE deleting anything
        for c in self.connections:c.close()
        self.inspect()
        for name,ident in self.files.items():
            p=self.root/name
            if p.exists():
                if identity(p)!=ident: raise RuntimeError('Replaced resource; retained')
                p.unlink()
        self.root.rmdir()

def manifest(c):
    objects=c.execute("SELECT type,name,tbl_name,sql FROM main.sqlite_master WHERE substr(name,1,7)<>'sqlite_' COLLATE NOCASE ORDER BY type,name").fetchall()
    tables={}
    for typ,name,_,sql in objects:
        if typ!='table':continue
        cols=c.execute('PRAGMA main.table_xinfo('+q(name)+')').fetchall()
        types=','.join('typeof('+q(col[1])+')' for col in cols)
        rows=c.execute('SELECT *,'+types+' FROM main.'+q(name)).fetchall()
        indexes=[]
        for idx in c.execute('PRAGMA main.index_list('+q(name)+')'):
            indexes.append((idx[1:],c.execute('PRAGMA main.index_xinfo('+q(idx[1])+')').fetchall()))
        tables[name]=(sql,cols,sorted(rows,key=repr),c.execute('PRAGMA main.foreign_key_list('+q(name)+')').fetchall(),sorted(indexes))
    seq=c.execute('SELECT name,typeof(name),seq,typeof(seq) FROM main.sqlite_sequence').fetchall()
    return {'objects':objects,'tables':tables,'sequences':sorted(seq,key=repr),'temp':c.execute('SELECT * FROM temp.sqlite_master ORDER BY name').fetchall()}

def protected(m):
    if sorted(set(m['tables'])-set(CANDIDATES))!=PROTECTED:raise RuntimeError('Not exactly26 protected tables')
    return {n:(m['tables'][n],[s for s in m['sequences'] if s[0]==n]) for n in PROTECTED}

def schema(m):return (m['objects'],{n:(v[0],v[1],v[3],v[4]) for n,v in m['tables'].items()},m['temp'])

def seed(c):
    tables=FIXTURE['tables']
    if len(tables)!=35 or sorted(set(tables)-set(CANDIDATES))!=PROTECTED:raise RuntimeError('Unexpected fixture tables')
    # Only CREATE definitions are accepted; never ATTACH, VACUUM INTO,
    # URI filenames, extension loaders or multi-statement input.
    c.enable_load_extension(False)
    for name,t in tables.items():
        if not re.match(r'^CREATE TABLE '+r'(?:'+re.escape(q(name))+'|'+re.escape(name)+r')\s*\(',t['sql'],re.I):raise RuntimeError('Unsafe fixture DDL')
    for o in FIXTURE['objects']:
        if o['type'] not in ['table','index','trigger']:raise RuntimeError('Unexpected fixture object')
        if o['type']!='table' and not re.match(r'^CREATE (?:UNIQUE )?'+o['type']+r'\s+',o['sql'],re.I):raise RuntimeError('Unsafe fixture object DDL')
    c.execute('BEGIN')
    for t in tables.values():c.execute(t['sql'])
    for o in FIXTURE['objects']:
        if o['type']=='index' and o['sql']:c.execute(o['sql'])
    left=dict(tables)
    while left:
        progressed=False
        for n,t in list(left.items()):
            if (set(t['parents'])-{n}) & set(left):continue
            for row in t['rows']:
                values=[base64.b64decode(v['blob']) if isinstance(v,dict) else v for v in row.values()]
                c.execute('INSERT INTO main.'+q(n)+'('+','.join(map(q,row))+') VALUES ('+','.join('?' for _ in row)+')',values)
            del left[n];progressed=True
        if not progressed:raise RuntimeError('Fixture dependency cycle')
    c.execute('DELETE FROM main.sqlite_sequence')
    c.executemany('INSERT INTO main.sqlite_sequence(name,seq) VALUES (?,?)',[(s['name'],s['seq']) for s in FIXTURE['sequences']])
    for o in FIXTURE['objects']:
        if o['type']=='trigger':c.execute(o['sql'])
    c.execute('COMMIT')

def atomic(c,key=900001):
    # Explicit lab idempotency logic, NOT a claim about Laravel workflow retries.
    if c.execute('SELECT 1 FROM main.jobs WHERE id=?',(key,)).fetchone():return False
    c.execute("INSERT INTO main.jobs(id,queue,payload,attempts,available_at,created_at) VALUES (?,'synthetic','{}',0,123,123)",(key,))
    c.execute("INSERT INTO main.cache(key,value,expiration) VALUES (?, 'committed',123)",('w62e-'+str(key),))
    return True

def native_transition(c):
    return c.execute("UPDATE main.service_reservations SET status='confirmed',version=2,last_reason='confirm',last_actor_user_id=(SELECT user_id FROM main.provider_profiles WHERE id=service_reservations.provider_profile_id) WHERE id=(SELECT MIN(id) FROM main.service_reservations) AND status='requested' AND version=1").rowcount

def child_worker(lab,pipe,mode):
    try:
        # A separate process and new connection against the same owned file.
        c=lab.connect();pipe.send(('connected',os.getpid(),c.execute('PRAGMA journal_mode').fetchone()[0]))
        command=pipe.recv()
        if command!='begin':raise RuntimeError('Bad barrier')
        try:
            c.execute('BEGIN IMMEDIATE');native_transition(c) if mode.startswith('native') else atomic(c);pipe.send(('written',os.getpid()))
        except sqlite3.OperationalError as e:
            pipe.send(('busy',getattr(e,'sqlite_errorcode',None)));command=pipe.recv()
            if command!='retry':raise
            c.execute('BEGIN IMMEDIATE');native_transition(c) if mode.startswith('native') else atomic(c);pipe.send(('written-retry',os.getpid()))
        if mode.endswith('before'):os._exit(73)
        command=pipe.recv()
        if command!='commit':raise RuntimeError('Bad commit barrier')
        c.execute('COMMIT');pipe.send(('committed',os.getpid()))
        if mode.endswith('after'):os._exit(74)
        c.close()
    except BaseException as e:pipe.send(('error',type(e).__name__,str(e)));os._exit(75)

class PhysicalTests(unittest.TestCase):
    def setUp(self):
        self.lab=Lab();self.addCleanup(self.release);self.c=self.lab.connect(create=True)
        self.assertEqual('wal',self.c.execute('PRAGMA journal_mode=WAL').fetchone()[0]);self.c.execute('PRAGMA wal_autocheckpoint=0')
        seed(self.c)
        self.c.execute("INSERT INTO main.jobs(id,queue,payload,attempts,available_at,created_at) VALUES (8900000,'synthetic-types','{}',1.25,123,123)")
        self.lab.sidecars('primary.sqlite');self.original=manifest(self.c)
    def release(self):
        events=list(self.lab.events);self.lab.cleanup()
        RESULTS[self._testMethodName]={'status':'CLEANUP PASS; scenario outcome in runner result','cleanup_root_removed':not self.lab.root.exists(),'connections':events}
    def receive(self,p):
        self.assertTrue(p.poll(8),'Explicit process barrier timed out');r=p.recv();self.assertNotEqual('error',r[0],r);return r
    def worker(self,mode='normal'):
        a,b=mp.get_context('spawn').Pipe();p=mp.get_context('spawn').Process(target=child_worker,args=(self.lab,b,mode));p.start();self.lab.children.append(p)
        message=self.receive(a);self.assertEqual('connected',message[0]);self.lab.events.append({'pid':message[1],'connection':'child independent','resource':'primary.sqlite','journal_mode':message[2]});return p,a
    def close_parent_handles(self):
        for c in self.lab.connections:c.close()
    def finish(self,p):
        p.join(8)
        if p.is_alive():
            p.terminate();p.join(5);self.fail('Owned child barrier timeout; scenario failed')
    def test_wal_snapshot_checkpoint_and_normal_reopen(self):
        reader=self.lab.connect();reader.execute('BEGIN');self.assertEqual(0,reader.execute('SELECT COUNT(*) FROM jobs WHERE id=900001').fetchone()[0])
        self.c.execute('BEGIN IMMEDIATE');atomic(self.c);self.c.execute('COMMIT')
        self.assertEqual(0,reader.execute('SELECT COUNT(*) FROM jobs WHERE id=900001').fetchone()[0])
        checkpoint=self.c.execute('PRAGMA wal_checkpoint(TRUNCATE)').fetchone();self.assertEqual(1,checkpoint[0])
        reader.execute('ROLLBACK');self.assertEqual((0,0,0),self.c.execute('PRAGMA wal_checkpoint(TRUNCATE)').fetchone())
        reopened=self.lab.connect();self.assertEqual(1,reopened.execute('SELECT COUNT(*) FROM jobs WHERE id=900001').fetchone()[0]);self.assertEqual('ok',reopened.execute('PRAGMA integrity_check').fetchone()[0])
        RESULTS['checkpoints']={'active_reader':checkpoint,'released':[0,0,0]}
    def test_two_process_writer_busy_retry_and_idempotency(self):
        p,pipe=self.worker();self.c.execute('BEGIN IMMEDIATE');self.c.execute("INSERT INTO cache VALUES ('writer-lock','held',123)")
        started=time.monotonic();pipe.send('begin');busy=self.receive(pipe);elapsed=time.monotonic()-started
        self.assertEqual(('busy',sqlite3.SQLITE_BUSY),busy);self.assertGreaterEqual(elapsed,0.10)
        self.c.execute('ROLLBACK');pipe.send('retry');self.assertEqual('written-retry',self.receive(pipe)[0]);pipe.send('commit');self.assertEqual('committed',self.receive(pipe)[0]);self.finish(p);self.assertEqual(0,p.exitcode)
        self.c.execute('BEGIN IMMEDIATE');self.assertFalse(atomic(self.c));self.c.execute('COMMIT')
        self.assertEqual(1,self.c.execute('SELECT COUNT(*) FROM jobs WHERE id=900001').fetchone()[0]);self.assertEqual(1,self.c.execute("SELECT COUNT(*) FROM cache WHERE key='w62e-900001'").fetchone()[0]);self.assertEqual([],self.c.execute('PRAGMA foreign_key_check').fetchall())
        RESULTS['contention']={'error_code':busy[1],'elapsed_seconds':elapsed,'busy_timeout_ms':150,'explicit_retry':True,'single_workflow':True}
    def test_process_exit_before_commit_recovers_last_committed_state(self):
        p,pipe=self.worker('before');self.close_parent_handles();pipe.send('begin');self.assertEqual('written',self.receive(pipe)[0]);self.finish(p);self.assertEqual(73,p.exitcode)
        reopened=self.lab.connect();self.assertEqual(self.original,manifest(reopened));self.assertEqual('ok',reopened.execute('PRAGMA integrity_check').fetchone()[0])
    def test_process_exit_after_commit_recovers_whole_workflow(self):
        p,pipe=self.worker('after');self.close_parent_handles();pipe.send('begin');self.assertEqual('written',self.receive(pipe)[0]);pipe.send('commit');self.assertEqual('committed',self.receive(pipe)[0]);self.finish(p);self.assertEqual(74,p.exitcode)
        reopened=self.lab.connect();self.assertEqual(1,reopened.execute('SELECT COUNT(*) FROM jobs WHERE id=900001').fetchone()[0]);self.assertEqual(1,reopened.execute("SELECT COUNT(*) FROM cache WHERE key='w62e-900001'").fetchone()[0]);self.assertEqual('ok',reopened.execute('PRAGMA integrity_check').fetchone()[0])
        # Every pre-existing protected row remains present, including native fixtures.
        after=manifest(reopened)
        for n,v in self.original['tables'].items():
            if n in PROTECTED:
                for row in v[2]:self.assertIn(row,after['tables'][n][2])
    def test_native_reservation_and_trigger_event_are_atomic_across_child_exits(self):
        initial_events=self.c.execute('SELECT COUNT(*) FROM reservation_events').fetchone()[0]
        p,pipe=self.worker('native-before');self.close_parent_handles();pipe.send('begin');self.assertEqual('written',self.receive(pipe)[0]);self.finish(p)
        self.assertEqual(73,p.exitcode);self.assertEqual(self.original,manifest(self.lab.connect()))
        p,pipe=self.worker('native-after');self.close_parent_handles();pipe.send('begin');self.assertEqual('written',self.receive(pipe)[0]);pipe.send('commit');self.assertEqual('committed',self.receive(pipe)[0]);self.finish(p)
        self.assertEqual(74,p.exitcode)
        reopened=self.lab.connect();self.assertEqual(('confirmed',2),reopened.execute('SELECT status,version FROM service_reservations').fetchone())
        self.assertEqual(initial_events+1,reopened.execute('SELECT COUNT(*) FROM reservation_events').fetchone()[0])
        reopened.execute('BEGIN IMMEDIATE');self.assertEqual(0,native_transition(reopened));reopened.execute('COMMIT')
        self.assertEqual(initial_events+1,reopened.execute('SELECT COUNT(*) FROM reservation_events').fetchone()[0])
    def backup(self,name):
        dest=self.lab.connect(name,create=True)
        self.c.backup(dest);self.assertEqual(manifest(self.c),manifest(dest));self.lab.sidecars(name);return dest
    def test_online_backup_restore_and_tamper_detection(self):
        self.c.execute('BEGIN IMMEDIATE');atomic(self.c);self.c.execute('COMMIT');source=manifest(self.c)
        self.assertGreater((self.lab.root/'primary.sqlite-wal').stat().st_size,0)
        backup=self.backup('backup.sqlite');restored=self.lab.connect('restore.sqlite',create=True);backup.backup(restored);manifest(restored);self.lab.sidecars('restore.sqlite')
        self.assertEqual(source,manifest(restored));self.assertEqual(protected(source),protected(manifest(restored)));self.assertEqual('ok',restored.execute('PRAGMA integrity_check').fetchone()[0]);self.assertEqual([],restored.execute('PRAGMA foreign_key_check').fetchall())
        self.assertEqual('real',restored.execute('SELECT typeof(attempts) FROM main.jobs WHERE id=8900000').fetchone()[0])
        self.assertEqual(('blob','0031FF'),restored.execute("SELECT typeof(value),hex(value) FROM main.cache WHERE key='synthetic-blob'").fetchone())
        with self.assertRaises(sqlite3.IntegrityError):restored.execute('INSERT INTO provider_weekly_intervals(provider_profile_id,day_of_week,start_minute,end_minute) SELECT provider_profile_id,day_of_week,start_minute,end_minute FROM provider_weekly_intervals LIMIT 1')
        with self.assertRaises(sqlite3.IntegrityError):restored.execute('INSERT INTO role_user(user_id,role_id) VALUES (99999,1)')
        self.assertEqual(source,manifest(restored))
        # Exercise actual restored constraints, without disabling triggers/FK.
        with self.assertRaises(sqlite3.IntegrityError):restored.execute("INSERT INTO users(name,email,password) SELECT name,email,password FROM users LIMIT 1")
        restored.execute("UPDATE users SET name='Synthetic altered backup' WHERE id=1");self.assertNotEqual(source,manifest(restored))
        with self.assertRaises(FileExistsError):self.lab.connect('restore.sqlite',create=True)
        tampered=self.lab.connect('tampered.sqlite',create=True);self.assertNotEqual(source,manifest_empty(tampered))
    def preflight(self,c,expected):
        actual=manifest(c)
        if schema(actual)!=schema(expected) or actual['temp']:raise RuntimeError('Unreviewed schema')
        if c.execute('PRAGMA main.foreign_key_check').fetchall():raise RuntimeError('Invalid FK')
        seen=set();seqs={}
        for name,typ,value,storage in actual['sequences']:
            if typ!='text' or name in seen or name not in actual['tables'] or storage!='integer' or not isinstance(value,int) or value<0:raise RuntimeError('Corrupt sequence')
            seen.add(name);seqs[name]=value
        for n,t in actual['tables'].items():
            if 'AUTOINCREMENT' in t[0].upper():
                pk=[i for i,v in enumerate(t[1]) if v[5]]
                if len(pk)!=1:raise RuntimeError('Invalid primary key')
                ids=[r[pk[0]] for r in t[2]]
                if (ids and n not in seqs) or (n in seqs and seqs[n]<max([0]+ids)):raise RuntimeError('Corrupt sequence high-water')
        for n,t in actual['tables'].items():
            for fk in t[3]:
                if fk[2] in CANDIDATES and (n not in CANDIDATES or CANDIDATES.index(n)>=CANDIDATES.index(fk[2])):raise RuntimeError('Unknown dependency')
        if protected(actual)!=protected(expected):raise RuntimeError('Protected drift')
    def retire(self,c,expected,stop=None):
        # Projection is derived from the reviewed baseline, never adopted from
        # an observed state that might already contain unknown changes.
        trace=[];reviewed=dict(expected,tables=dict(expected['tables']))
        for name in CANDIDATES:
            self.preflight(c,reviewed)
            c.execute('DROP TABLE main.'+q(name));trace.append(name)
            reviewed['tables']={n:v for n,v in reviewed['tables'].items() if n!=name}
            reviewed['objects']=[o for o in reviewed['objects'] if o[2]!=name]
            reviewed['sequences']=[row for row in reviewed['sequences'] if row[0]!=name]
            if len(trace)==stop:raise RuntimeError('Injected before commit')
        self.preflight(c,reviewed)
        return trace
    def test_synthetic_retirement_rollback_persists_all_26_tables(self):
        self.c.execute('BEGIN IMMEDIATE')
        with self.assertRaisesRegex(RuntimeError,'Injected'):
            self.retire(self.c,self.original,3)
        self.c.execute('ROLLBACK')
        self.assertEqual(self.original,manifest(self.lab.connect()));self.assertEqual(26,len(protected(self.original)))
    def test_committed_retirement_with_reader_and_independent_restore(self):
        reader=self.lab.connect();reader.execute('BEGIN');old=manifest(reader)
        self.c.execute('BEGIN IMMEDIATE');trace=self.retire(self.c,self.original);self.c.execute('COMMIT')
        self.assertEqual(CANDIDATES,trace);self.assertEqual(old,manifest(reader))
        reader.execute('ROLLBACK');after=manifest(reader)
        self.assertEqual(PROTECTED,sorted(after['tables']));self.assertEqual(protected(self.original),protected(after))
        restored=self.backup('restore.sqlite');self.assertEqual(after,manifest(restored))
    def test_retirement_serializes_with_competing_protected_writer(self):
        p,pipe=self.worker();self.c.execute('BEGIN IMMEDIATE');pipe.send('begin');self.assertEqual('busy',self.receive(pipe)[0])
        self.retire(self.c,self.original);self.c.execute('ROLLBACK')
        self.assertEqual(self.original,manifest(self.c));pipe.send('retry');self.assertEqual('written-retry',self.receive(pipe)[0]);pipe.send('commit');self.assertEqual('committed',self.receive(pipe)[0]);self.finish(p)
        self.assertEqual(0,p.exitcode);self.assertEqual(35,len(manifest(self.c)['tables']))
    def restore(self,source,name,expected):
        # Validate an owned source and complete manifest BEFORE allocating target.
        self.lab.inspect()
        if manifest(source)!=expected or source.execute('PRAGMA integrity_check').fetchone()[0]!='ok' or source.execute('PRAGMA foreign_key_check').fetchall():raise RuntimeError('Backup rejected')
        dest=self.lab.connect(name,create=True);source.backup(dest)
        self.assertEqual(expected,manifest(dest));self.lab.sidecars(name)
        return dest
    def test_incomplete_and_altered_backups_rejected_before_destination_creation(self):
        empty=self.lab.connect('backup.sqlite',create=True)
        with self.assertRaises((RuntimeError,sqlite3.DatabaseError)):self.restore(empty,'restore.sqlite',self.original)
        self.assertFalse((self.lab.root/'restore.sqlite').exists())
        self.c.backup(empty);manifest(empty);self.lab.sidecars('backup.sqlite')
        empty.execute("UPDATE users SET name='Synthetic tamper'")
        with self.assertRaisesRegex(RuntimeError,'Backup rejected'):self.restore(empty,'restore.sqlite',self.original)
        self.assertFalse((self.lab.root/'restore.sqlite').exists())
    def test_temp_shadow_schema_drift_and_high_water_stop_before_retirement(self):
        self.c.execute('CREATE TEMP VIEW users AS SELECT 999 AS id')
        with self.assertRaisesRegex(RuntimeError,'Unreviewed'):self.retire(self.c,self.original)
        self.c.execute('DROP VIEW temp.users')
        self.c.execute("UPDATE main.sqlite_sequence SET seq=0 WHERE name='users'")
        with self.assertRaisesRegex(RuntimeError,'high-water'):self.retire(self.c,self.original)
        self.assertEqual(35,len(manifest(self.c)['tables']))
    def test_unregistered_sidecar_is_not_claimed_by_cleanup(self):
        # An existing owned sidecar cannot be replaced even by another file
        # created by this test. No automatic registry refresh is allowed.
        unknown=self.lab.root/'backup.sqlite-wal';unknown.write_bytes(b'synthetic')
        ident=identity(unknown)
        with self.assertRaisesRegex(RuntimeError,'Unknown'):self.lab.cleanup()
        self.assertTrue((self.lab.root/'primary.sqlite').exists());self.assertEqual(ident,identity(unknown));unknown.unlink()
    def test_unknown_dependency_and_corrupt_sequence_reject_before_drop(self):
        self.c.execute('CREATE TABLE sqliteX_cross(id INTEGER PRIMARY KEY,post_id INTEGER REFERENCES posts(id) ON DELETE CASCADE)');self.c.execute('INSERT INTO sqliteX_cross VALUES (1,1)')
        with self.assertRaisesRegex(RuntimeError,'Unreviewed'):self.preflight(self.c,self.original)
        self.assertEqual(1,self.c.execute('SELECT COUNT(*) FROM sqliteX_cross').fetchone()[0]);self.assertTrue(all(n in manifest(self.c)['tables'] for n in CANDIDATES))
        self.c.execute('DROP TABLE sqliteX_cross');self.c.execute("UPDATE main.sqlite_sequence SET seq='1-corrupt' WHERE name='users'")
        with self.assertRaisesRegex(RuntimeError,'Corrupt'):self.preflight(self.c,self.original)
    def test_ownership_marker_unknown_file_and_symlink_stop_safely(self):
        p=self.lab.root/'unknown';p.write_bytes(b'synthetic');ident=identity(p)
        with self.assertRaisesRegex(RuntimeError,'Unknown'):self.lab.inspect()
        self.assertTrue((self.lab.root/'primary.sqlite').exists());self.assertEqual(ident,identity(p));p.unlink() # creator removes its own adversarial fixture
        p=self.lab.root/'restore.sqlite';p.symlink_to(self.lab.root/'primary.sqlite');ident=identity(p)
        with self.assertRaises(RuntimeError):self.lab.connect('restore.sqlite')
        self.assertEqual(ident,identity(p));p.unlink()
        with self.assertRaises(RuntimeError):self.lab.create('../outside.sqlite')
        marker=self.lab.root/'owner.json';original=marker.read_text();marker.write_text('{"token":"wrong"}')
        with self.assertRaises(RuntimeError):self.lab.inspect()
        marker.write_text(original);self.lab.inspect()

def manifest_empty(c):
    return {'objects':c.execute('SELECT * FROM main.sqlite_master').fetchall()}

if __name__=='__main__':
    if len(sys.argv)!=1:raise SystemExit('No paths or CLI parameters accepted')
    FIXTURE=json.load(sys.stdin)
    if len(FIXTURE['tables'])!=35:raise RuntimeError('Unexpected fixture size')
    result=unittest.TextTestRunner(verbosity=2,stream=sys.stderr).run(unittest.defaultTestLoader.loadTestsFromTestCase(PhysicalTests))
    print(json.dumps({'status':'PASS' if result.wasSuccessful() else 'FAIL','tests':result.testsRun,'failures':len(result.failures),'errors':len(result.errors),'python':sys.version.split()[0],'sqlite':sqlite3.sqlite_version,'synchronous':2,'journal_mode':'wal','process_start_method':'spawn','scenarios':RESULTS,'limits':['No power-loss/hardware/fsync certification','Python SQLite engine checks; Laravel workflows not executed concurrently','Windows and PHP SQLite persistent runtime NOT RUN']},indent=2))
    sys.exit(not result.wasSuccessful())
