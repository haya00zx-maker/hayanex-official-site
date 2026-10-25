"use strict";
(() => {
  const form = document.getElementById("contact-prototype");
  if (!form) return;
  const keys = ["type","client","name","organization","email","phone","service","message","budget","deadline"];
  const required = ["type","client","name","email","service","message"];
  const $ = id => document.getElementById(id);
  const review = $("cp-review-panel");
  function clearErrors() {
    $("cp-error").textContent = "";
    keys.concat(["consent", "demo"]).forEach(k => {
      const el = $("cp-" + k);
      el.removeAttribute("aria-invalid");
      $("err-" + k).textContent = "";
    });
  }
  function validate() {
    clearErrors();
    let first = null, count = 0;
    for (const k of required.concat(["consent","demo"])) {
      const el = $("cp-" + k);
      const invalid = el.type === "checkbox" ? !el.checked : !el.value.trim();
      if (invalid) {
        $("err-" + k).textContent = "必須項目です。架空データで入力してください。";
        el.setAttribute("aria-invalid", "true");
        if (!first) first = el;
        count++;
      }
    }
    const email = $("cp-email");
    if (email.value && !email.checkValidity()) {
      $("err-email").textContent = "メール形式を確認してください。";
      email.setAttribute("aria-invalid","true");
      if (!first) first=email;
      count++;
    }
    const client = $("cp-client").value;
    if ((client === "corporate" || client === "group") && !$("cp-organization").value.trim()) {
      $("err-organization").textContent = "法人・団体の場合は名称をご入力ください。";
      $("cp-organization").setAttribute("aria-invalid","true");
      if (!first) first=$("cp-organization");
      count++;
    }
    if(first) { $("cp-error").textContent = count + "件の入力内容を確認してください。送信はしていません。"; first.focus(); return false; }
    return true;
  }
  $("cp-review").addEventListener("click", () => {
    if (!validate()) return;
    form.querySelector("fieldset").hidden = true;
    review.hidden = false;
    review.focus();
  });
  $("cp-back").addEventListener("click", () => {
    review.hidden = true;
    form.querySelector("fieldset").hidden = false;
    $("cp-review").focus();
  });
  $("cp-sample").addEventListener("click", () => {
    form.reset();
    Object.entries({type:"estimate",client:"person",name:"サンプル太郎",email:"sample@example.com",service:"web",message:"架空のテスト用お問い合わせです。",budget:"10万円",deadline:"11月末"}).forEach(([k,v]) => $("cp-"+k).value=v);
    clearErrors();
  });
  form.addEventListener("submit", e => e.preventDefault());
  form.addEventListener("reset", () => {
    clearErrors();
    review.hidden = true;
    form.querySelector("fieldset").hidden = false;
  });
  form.addEventListener("input", clearErrors);
  window.addEventListener("pagehide", () => form.reset());
  window.addEventListener("pageshow", () => form.reset());
})();