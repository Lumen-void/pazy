const payments = [
  ["Acme Cloud Services", "SaaS", "₹2,84,000", "Riya", "Pending"],
  ["Northstar Logistics", "Freight", "₹1,48,500", "Kabir", "Approved"],
  ["Vertex Office", "Procurement", "₹82,900", "Maya", "Pending"],
  ["Bluefin Consultants", "Services", "₹3,15,400", "Arjun", "Tax match"]
];

const connectors = [
  ["RazorpayX", "Connected"],
  ["Zoho Books", "Connected"],
  ["GST Portal", "Demo"],
  ["Slack Alerts", "Connected"],
  ["Bank Statement SFTP", "Manual"]
];

const audit = [
  ["Batch PAY-114 approved", "Maker-checker flow completed by demo CFO"],
  ["Invoice INV-908 matched", "PO, GRN and GSTIN validated"],
  ["Vendor bank change blocked", "Policy requires second approver"],
  ["Cash forecast refreshed", "Sample bank balance imported"]
];

document.querySelector("#paymentRows").innerHTML = payments.map(row => `
  <tr>
    <td><strong>${row[0]}</strong></td>
    <td>${row[1]}</td>
    <td>${row[2]}</td>
    <td>${row[3]}</td>
    <td><span class="badge ${row[4] === "Approved" ? "ok" : "warn"}">${row[4]}</span></td>
  </tr>
`).join("");

document.querySelector("#connectors").innerHTML = connectors.map(row => `
  <div class="connector">
    <strong>${row[0]}</strong>
    <span class="badge ${row[1] === "Connected" ? "ok" : "warn"}">${row[1]}</span>
  </div>
`).join("");

document.querySelector("#auditRows").innerHTML = audit.map(item => `
  <li><strong>${item[0]}</strong><span>${item[1]}</span></li>
`).join("");

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
  link.addEventListener("click", () => {
    document.querySelectorAll("nav a").forEach(item => item.classList.remove("active"));
    link.classList.add("active");
  });
});

document.querySelector("#queueFilter").addEventListener("change", event => {
  showToast(`${event.target.value} selected for the sample payment queue.`);
});
