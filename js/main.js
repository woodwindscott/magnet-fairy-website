// The Magnet Fairy — contact form behavior
//
// Submits to Formspree (https://formspree.io) via fetch, so the page never
// reloads. On success, shows a confirmation message. On failure (network
// error, Formspree rejects it, etc.), shows an error message and re-enables
// the form so the visitor can try again.

document.addEventListener("DOMContentLoaded", function () {
  var form = document.getElementById("contact-form");
  var submitBtn = document.getElementById("submit-btn");
  var successMsg = document.getElementById("form-success");
  var errorMsg = document.getElementById("form-error");

  if (!form) return;

  form.addEventListener("submit", function (event) {
    event.preventDefault();

    errorMsg.hidden = true;
    submitBtn.disabled = true;
    submitBtn.textContent = "Sending...";

    fetch(form.action, {
      method: "POST",
      body: new FormData(form),
      headers: { Accept: "application/json" },
    })
      .then(function (response) {
        if (response.ok) {
          submitBtn.textContent = "Sent!";
          successMsg.hidden = false;
          form.reset();
        } else {
          throw new Error("Form submission failed");
        }
      })
      .catch(function () {
        submitBtn.disabled = false;
        submitBtn.textContent = "Send message";
        errorMsg.hidden = false;
      });
  });
});
