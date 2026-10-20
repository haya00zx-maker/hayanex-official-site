"use strict";
// 描画前にメニューの領域を確定し、低速読込時のレイアウト移動を防ぐ。
(() => {
  const root = document.documentElement;
  root.classList.add("js");
  let ready = false;
  const restoreLinks = () => {
    if (!ready) root.classList.remove("js");
  };
  const timer = window.setTimeout(restoreLinks, 3000);
  document.addEventListener(
    "error",
    (event) => {
      if (
        event.target instanceof HTMLScriptElement &&
        event.target.src.endsWith("/site.js")
      )
        restoreLinks();
    },
    true,
  );
  window.hayanexMenuReady = () => {
    ready = true;
    window.clearTimeout(timer);
    delete window.hayanexMenuReady;
  };
})();
