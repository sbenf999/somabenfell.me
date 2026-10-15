// change this to your real email address
const EMAIL = "you@example.com";

const form = document.getElementById("contact-form");
const formStatus = document.getElementById("form-status");

// there's no backend, so the form opens the visitor's email app
// with the message already filled in
form.addEventListener("submit", function (event) {
  event.preventDefault();

  const data = new FormData(form);
  const name = data.get("name");
  const email = data.get("email");
  const message = data.get("message");

  const subject = "Portfolio message from " + name;
  const body = "From: " + name + " (" + email + ")\n\n" + message;

  window.location.href =
    "mailto:" + EMAIL +
    "?subject=" + encodeURIComponent(subject) +
    "&body=" + encodeURIComponent(body);

  formStatus.textContent =
    "Opening your email app. If nothing happens, email " + EMAIL + " directly.";
});
