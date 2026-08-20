// The Magnet Fairy — contact form behavior
//
// NOTE: This is a placeholder site with no backend. The form below only
// simulates a successful submission in the browser — it does not actually
// send an email or store the message anywhere. Before relying on this form
// to capture real leads, wire it up to a form backend such as Formspree
// (https://formspree.io) or Netlify Forms, or point it at a serverless
// function that sends you an email. See README.md for notes.

document.addEventListener("DOMContentLoaded", function () {
  var form = document.getElementById("contact-form");
  var submitBtn = document.getElementById("submit-btn");
  var successMsg = document.getElementById("form-success");

  if (!form) return;

  form.addEventListener("submit", function (event) {
    event.preventDefault();

    // TODO: replace this simulated success with a real submission,
    // e.g. fetch('https://formspree.io/f/your-id', { method: 'POST', body: new FormData(form) })

    submitBtn.textContent = "Sent!";
    submitBtn.disabled = true;
    successMsg.hidden = false;
  });
});
