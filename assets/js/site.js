"use strict";
(() => {
  const button = document.querySelector(".nav-toggle");
  const nav = document.querySelector("#primary-nav");
  if (!button || !nav) return;
  const mobile = window.matchMedia("(max-width: 760px)");
  const setOpen = (open, restoreFocus = false) => {
    nav.classList.toggle("is-open", open);
    button.setAttribute("aria-expanded", String(open));
    button.setAttribute(
      "aria-label",
      open ? "メニューを閉じる" : "メニューを開く",
    );
    if (restoreFocus) button.focus();
  };
  // 操作イベントとARIA状態を揃えてから、読込完了を通知する。
  button.addEventListener("click", () =>
    setOpen(button.getAttribute("aria-expanded") !== "true"),
  );
  document.addEventListener("keydown", (event) => {
    if (
      event.key === "Escape" &&
      mobile.matches &&
      nav.classList.contains("is-open")
    )
      setOpen(false, true);
  });
  nav.addEventListener("click", (event) => {
    const link = event.target.closest("a");
    if (!link || !mobile.matches) return;
    setOpen(false);
    if (link.hash && link.pathname === location.pathname) {
      const target = document.getElementById(link.hash.slice(1));
      if (target) {
        target.setAttribute("tabindex", "-1");
        target.focus({ preventScroll: true });
      }
    }
  });
  document.addEventListener("click", (event) => {
    if (mobile.matches && !event.target.closest(".site-header")) setOpen(false);
  });
  mobile.addEventListener("change", () => setOpen(false));
  setOpen(false);
  document.documentElement.classList.add("js");
  window.hayanexMenuReady?.();
})();
