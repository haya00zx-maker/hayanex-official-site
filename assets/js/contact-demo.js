"use strict";
(() => {
  const container = document.getElementById("contact-demo");
  if (!container) return;
  const fields = ["name", "email", "category", "message", "test-only"].map(
    (key) => document.getElementById(`demo-${key}`),
  );
  const status = document.getElementById("demo-status");
  const errors = document.getElementById("demo-errors");
  const clearFeedback = () => {
    status.textContent = "";
    errors.textContent = "";
    for (const field of fields) {
      field.removeAttribute("aria-invalid");
      document.getElementById(`${field.id}-error`).textContent = "";
    }
  };
  const clearValues = () => {
    for (const field of fields) {
      if (field.type === "checkbox") field.checked = false;
      else field.value = "";
    }
    clearFeedback();
  };
  const errorFor = (field) => {
    if (field.type === "checkbox")
      return field.checked
        ? ""
        : "架空データのみを使い、送信されないことの確認が必要です。";
    const value = field.value.trim();
    if (!value) return "この項目を入力・選択してください。";
    if (field.maxLength > 0 && value.length > field.maxLength)
      return `${field.maxLength.toLocaleString("ja-JP")}文字以内で入力してください。`;
    if (field.type === "email" && field.validity.typeMismatch)
      return "メールアドレスの形式を確認してください。例：sample@example.com";
    if (!field.validity.valid) return "入力形式を確認してください。";
    return "";
  };
  document.getElementById("demo-check").addEventListener("click", () => {
    clearFeedback();
    const invalid = fields.filter((field) => {
      const message = errorFor(field);
      if (!message) return false;
      field.setAttribute("aria-invalid", "true");
      document.getElementById(`${field.id}-error`).textContent = message;
      return true;
    });
    if (invalid.length) {
      errors.textContent = `${invalid.length}項目の入力を確認してください。送信は行っていません。`;
      invalid[0].focus();
      return;
    }
    // Only a generic status is rendered. Input values are never transmitted or copied into HTML.
    status.textContent =
      "入力形式の確認が完了しました。送信・保存・受付は行っていません。お問い合わせは成立していません。";
  });
  document.getElementById("demo-clear").addEventListener("click", () => {
    clearValues();
    status.textContent = "入力内容を消去しました。";
  });
  document.getElementById("demo-sample").addEventListener("click", () => {
    clearFeedback();
    fields[0].value = "サンプル";
    fields[1].value = "sample@example.com";
    fields[2].value = "web";
    fields[3].value = "架空の入力確認用サンプルです。";
    fields[4].checked = false;
    status.textContent =
      "架空のサンプルを入れました。確認項目を選んで入力形式を確認できます。";
  });
  container.addEventListener("input", clearFeedback);
  container.addEventListener("change", clearFeedback);
  window.addEventListener("pagehide", clearValues);
  window.addEventListener("pageshow", clearValues);
  clearValues();
  document.getElementById("demo-fields").disabled = false;
})();
