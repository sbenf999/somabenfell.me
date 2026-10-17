const EMAIL = "hello@somabenfell.me";

const form = document.getElementById("contact-form");
const formStatus = document.getElementById("form-status");
const submitButton = form.querySelector("button[type='submit']");

// remember when the page loaded, so the server can reject forms that were sent suspiciously fast
form.elements["started"].value = Date.now();

form.addEventListener("submit", async function (event) {
  event.preventDefault();

  submitButton.disabled = true;
  formStatus.textContent = "Sending...";

  try {
    const response = await fetch("/contact.php", {
      method: "POST",
      body: new FormData(form)
    });

    // if the server sends back something that isn't JSON, treat it as an error
    const result = await response.json().catch(function () {
      return {};
    });

    if (response.ok && result.ok) {
      form.reset();
      form.elements["started"].value = Date.now();
      formStatus.textContent = "Thanks, your message has been sent.";
    } else {
      formStatus.textContent =
        result.error || "Something went wrong. Please try again.";
    }
  } catch (error) {
    formStatus.textContent =
      "Couldn't reach the server. You can email me at " + EMAIL + " instead.";
  }

  submitButton.disabled = false;
});