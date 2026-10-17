const EMAIL = "hello@somabenfell.me";

const form = document.getElementById("contact-form");
const toast = document.getElementById("toast");
const submitButton = form.querySelector("button[type='submit']");

let toastTimer;

// shows a small popup message that goes away by itself
function showToast(message, isError) {
  clearTimeout(toastTimer);

  toast.textContent = message;
  toast.classList.toggle("error", isError);
  toast.classList.add("show");

  // errors stay a bit longer so they can be read
  toastTimer = setTimeout(function () {
    toast.classList.remove("show");
  }, isError ? 6000 : 4000);
}

// remember when the page loaded, so the server can reject forms
// that were sent suspiciously fast
form.elements["started"].value = Date.now();

form.addEventListener("submit", async function (event) {
  event.preventDefault();

  submitButton.disabled = true;
  submitButton.textContent = "Sending...";

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
      showToast("Message sent, thank you!", false);
    } else {
      showToast(result.error || "Something went wrong. Please try again.", true);
    }
  } catch (error) {
    showToast("Couldn't reach the server. You can email me at " + EMAIL + " instead.", true);
  }

  submitButton.disabled = false;
  submitButton.textContent = "Send message";
});