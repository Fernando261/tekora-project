const navigation = document.querySelector("[data-account-navigation]");
if (navigation) {
    const desktop = matchMedia("(min-width: 1100px)");
    const summary = navigation.querySelector("summary");
    const sync = () => {
        navigation.open = desktop.matches;
    };
    sync();
    navigation.dataset.navigationReady = "true";
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
