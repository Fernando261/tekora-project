const navigation = document.querySelector("[data-account-navigation]");
if (navigation) {
    const desktop = matchMedia("(min-width: 1100px)");
    const summary = navigation.querySelector("summary");
    const scroller = navigation.querySelector("[data-sidebar-scroll]");
    const sidebar = scroller?.closest(".sidebar");
    const updateScroll = () => {
        if (!scroller || !sidebar) return;
        const before = String(scroller.scrollTop > 1);
        const after = String(
            scroller.scrollHeight - scroller.clientHeight - scroller.scrollTop > 1,
        );
        if (sidebar.dataset.scrollBefore !== before)
            sidebar.dataset.scrollBefore = before;
        if (sidebar.dataset.scrollAfter !== after)
            sidebar.dataset.scrollAfter = after;
    };
    // Keep the whole focus ring clear of the 18px edge indicators. Scroll only
    // this panel; the document and dashboard keep their own native scrolling.
    const reveal = (target) => {
        if (!scroller || !target || !navigation.open) return;
        const bounds = scroller.getBoundingClientRect();
        const item = target.getBoundingClientRect();
        if (item.top < bounds.top + 24)
            scroller.scrollTop += item.top - bounds.top - 24;
        else if (item.bottom > bounds.bottom - 24)
            scroller.scrollTop += item.bottom - bounds.bottom + 24;
        updateScroll();
    };
    const sync = () => {
        navigation.open = desktop.matches;
    };
    sync();
    navigation.dataset.navigationReady = "true";
    if (scroller) {
        scroller.addEventListener("scroll", updateScroll, { passive: true });
        scroller.addEventListener("focusin", (event) => {
            if (event.target !== scroller) reveal(event.target);
        });
        navigation.addEventListener("toggle", updateScroll, true);
        const resize = new ResizeObserver(updateScroll);
        resize.observe(scroller);
        resize.observe(scroller.querySelector("nav"));
        updateScroll();
        reveal(scroller.querySelector('[aria-current="page"]'));
    }
    desktop.addEventListener("change", sync);
    navigation.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && !desktop.matches && navigation.open) {
            navigation.open = false;
            summary.focus();
        }
    });
    navigation.addEventListener("click", (event) => {
        if (event.target.closest("a") && !desktop.matches)
            navigation.open = false;
    });
}
