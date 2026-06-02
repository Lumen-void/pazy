const connectors = [
  ["Banking provider", "Linked"],
  ["Accounting system", "Linked"],
  ["Tax portal", "Demo"],
  ["Alert channel", "Linked"],
  ["Statement import", "Manual"]
];

document.querySelector("#paymentRows").innerHTML = `
  <tr>
    <td colspan="5" class="empty-cell">
      <strong>No payment table rows are published.</strong>
      <span>Vendor, category, amount, owner and status values are cleared from the public deployment.</span>
    </td>
  </tr>
`;

document.querySelector("#connectors").innerHTML = connectors.map(row => `
  <div class="connector">
    <strong>${row[0]}</strong>
    <span class="badge ${row[1] === "Linked" ? "ok" : "warn"}">${row[1]}</span>
  </div>
`).join("");

document.querySelector("#auditRows").innerHTML = `
  <li><strong>No audit events are published.</strong><span>Real actor, invoice, bank and approval records stay in the local database.</span></li>
`;

const toast = document.querySelector("#toast");
const showToast = (message) => {
  toast.textContent = message;
  toast.classList.add("show");
  clearTimeout(window.pazyToast);
  window.pazyToast = setTimeout(() => toast.classList.remove("show"), 2600);
};

document.querySelectorAll("[data-action]").forEach(button => {
  button.addEventListener("click", () => {
    showToast(button.dataset.action === "pay"
      ? "Demo payout released with masked bank data."
      : "Sample approval batch marked complete.");
  });
});

document.querySelectorAll("nav a").forEach(link => {
  const route = link.dataset.route;
  const page = window.location.pathname.split("/").pop().replace(".html", "") || "dashboard";
  link.classList.toggle("active", route === page || (page === "index" && route === "dashboard"));
});

const currentPage = window.location.pathname.split("/").pop().replace(".html", "") || "dashboard";
const target = document.querySelector(`#${currentPage}`);
if (target && currentPage !== "dashboard" && currentPage !== "index") {
  requestAnimationFrame(() => target.scrollIntoView({ block: "start" }));
}

document.querySelector("#queueFilter").addEventListener("change", event => {
  showToast(`${event.target.value} selected for the sample payment queue.`);
});
