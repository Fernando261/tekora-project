"""Independent negative probes; run only against the lab's newly owned resources."""
import copy
import json
import os
import sys
import unittest
import persistent_lab as lab

class Adversarial(lab.PhysicalTests):
    def test_adverse_existing_destination_is_never_overwritten(self):
        target=self.lab.connect('backup.sqlite',create=True)
        target.execute('CREATE TABLE synthetic_preserved(value TEXT)')
        before=(self.lab.root/'backup.sqlite').read_bytes()
        with self.assertRaises(FileExistsError):self.backup('backup.sqlite')
        self.assertEqual(before,(self.lab.root/'backup.sqlite').read_bytes())
    def test_adverse_external_and_reused_names_are_rejected(self):
        with self.assertRaises(RuntimeError):self.lab.connect('../external.sqlite')
        with self.assertRaises(FileExistsError):self.lab.create('primary.sqlite')
    def test_adverse_fixture_cannot_attach_or_select_an_existing_path(self):
        original=lab.FIXTURE
        try:
            lab.FIXTURE=copy.deepcopy(original)
            lab.FIXTURE['tables']['users']['sql']="ATTACH DATABASE '/never-opened' AS stolen"
            target=self.lab.connect('backup.sqlite',create=True)
            with self.assertRaisesRegex(RuntimeError,'Unsafe fixture'):lab.seed(target)
            self.assertEqual([],target.execute('SELECT * FROM main.sqlite_master').fetchall())
        finally:lab.FIXTURE=original
    def test_adverse_replaced_resource_blocks_before_delete(self):
        original=self.lab.create('backup.sqlite');saved=self.lab.root/'saved';original.rename(saved)
        original.write_bytes(b'synthetic replacement');replacement=lab.identity(original)
        with self.assertRaises(RuntimeError):self.lab.inspect()
        self.assertTrue(saved.exists());self.assertEqual(replacement,lab.identity(original))
        original.unlink();saved.rename(original)
    def test_adverse_temporary_homonym_stops_before_first_drop(self):
        self.c.execute('CREATE TEMP TABLE "main.sqlite_sequence"(hidden TEXT)')
        trace=[];self.c.set_trace_callback(trace.append)
        with self.assertRaises(RuntimeError):self.retire(self.c,self.original)
        self.assertEqual([], [s for s in trace if s.lstrip().lower().startswith('drop')])
        self.c.set_trace_callback(None)

if __name__=='__main__':
    lab.FIXTURE=json.load(sys.stdin)
    names=[n for n in dir(Adversarial) if n.startswith('test_adverse_')]
    result=unittest.TextTestRunner(verbosity=2).run(unittest.TestSuite(Adversarial(n) for n in names))
    print(json.dumps({'status':'PASS' if result.wasSuccessful() else 'FAIL','tests':result.testsRun,'errors':len(result.errors),'failures':len(result.failures),'scope':'independent adversarial probes; owned synthetic resources only','cleanup':lab.RESULTS},indent=2))
    sys.exit(not result.wasSuccessful())
