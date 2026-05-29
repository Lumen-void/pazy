<template>
  <section class="dashboard-shell">
    <header class="hero">
      <p class="eyebrow">Pazy Finance Ops</p>
      <h1>Unified Spend and Payables Command Center</h1>
      <p class="subtitle">Laravel 12 + Vue 3 module shell for AP, procurement, reimbursements, payments, tax, and compliance flows.</p>
    </header>

    <div class="grid">
      <article v-for="card in cards" :key="card.title" class="card">
        <h2>{{ card.title }}</h2>
        <p>{{ card.description }}</p>
      </article>
    </div>
  </section>
</template>

<script setup>
const cards = [
  { title: 'Accounts Payable', description: 'Invoice capture, OCR extraction, 3-way match, and approval routing.' },
  { title: 'Procurement', description: 'PO and GRN workflows with discrepancy management.' },
  { title: 'Reimbursements', description: 'Policy-aware expense claims with mileage and payout orchestration.' },
  { title: 'Payments', description: 'Maker-checker payment initiation, execution, callbacks, and audit trails.' },
  { title: 'Tax Reconciliation', description: 'Jurisdiction adapter framework with release/hold recommendations.' },
  { title: 'Integrations', description: 'Stub provider contracts for banks, ERP, OCR, messaging, and identity checks.' },
];
</script>

<style scoped>
.dashboard-shell {
  max-width: 1100px;
  margin: 0 auto;
  padding: 2rem 1rem 3rem;
  color: #1f2937;
}

.hero {
  margin-bottom: 1.5rem;
}

.eyebrow {
  text-transform: uppercase;
  letter-spacing: 0.14em;
  font-weight: 700;
  color: #0f766e;
  margin-bottom: 0.5rem;
}

h1 {
  font-size: clamp(1.8rem, 4vw, 2.6rem);
  margin: 0 0 0.6rem;
}

.subtitle {
  max-width: 72ch;
  color: #4b5563;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
  gap: 0.9rem;
}

.card {
  border-radius: 14px;
  border: 1px solid #cbd5e1;
  background: linear-gradient(135deg, #ffffff 0%, #f0fdfa 100%);
  padding: 1rem;
}

.card h2 {
  margin: 0 0 0.45rem;
  font-size: 1rem;
}

.card p {
  margin: 0;
  font-size: 0.92rem;
  color: #334155;
}
</style>
