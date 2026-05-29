document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-confirm]').forEach((element) => {
    element.addEventListener('click', (event) => {
      const message = element.getAttribute('data-confirm') || 'Are you sure?';
      if (!window.confirm(message)) {
        event.preventDefault();
      }
    });
  });

  const sourceInvoice = document.getElementById('source_invoice');
  const payeeInput = document.getElementById('payee_id');
  const amountInput = document.getElementById('payment_amount');
  const expenseFiles = document.getElementById('expense_files');
  const proofCount = document.getElementById('proof_count');
  const distanceKm = document.getElementById('distance_km');
  const mileageRate = document.getElementById('mileage_rate');
  const mileagePreview = document.getElementById('mileage_amount_preview');

  document.body.classList.add('js-ready');

  const scrollProgress = document.createElement('div');
  scrollProgress.className = 'scroll-progress';
  document.body.appendChild(scrollProgress);

  let scrollTicking = false;
  const updateScrollProgress = () => {
    const root = document.documentElement;
    const maxScrollable = Math.max(1, root.scrollHeight - window.innerHeight);
    const ratio = Math.min(1, Math.max(0, window.scrollY / maxScrollable));
    scrollProgress.style.transform = `scaleX(${ratio})`;
    document.body.classList.toggle('has-scrolled', window.scrollY > 16);
    scrollTicking = false;
  };

  window.addEventListener('scroll', () => {
    if (scrollTicking) {
      return;
    }
    scrollTicking = true;
    window.requestAnimationFrame(updateScrollProgress);
  }, { passive: true });
  updateScrollProgress();

  const publicNav = document.querySelector('.public-nav');
  const publicNavToggle = document.querySelector('[data-public-nav-toggle]');
  const publicNavPanel = document.getElementById('publicNavPanel');

  const setPublicNavOpen = (open) => {
    if (!publicNav || !publicNavToggle) {
      return;
    }
    publicNav.classList.toggle('is-open', open);
    publicNavToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  };

  if (publicNavToggle && publicNav) {
    publicNavToggle.addEventListener('click', () => {
      const next = !publicNav.classList.contains('is-open');
      setPublicNavOpen(next);
    });

    document.addEventListener('click', (event) => {
      if (window.innerWidth > 900) {
        return;
      }
      if (!publicNav.contains(event.target)) {
        setPublicNavOpen(false);
      }
    });

    if (publicNavPanel) {
      publicNavPanel.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
          if (window.innerWidth <= 900) {
            setPublicNavOpen(false);
          }
        });
      });
    }

    window.addEventListener('resize', () => {
      if (window.innerWidth > 900) {
        setPublicNavOpen(false);
      }
    });
  }

  const appSidebarToggle = document.querySelector('[data-app-sidebar-toggle]');
  const appSidebarClose = document.querySelector('[data-app-sidebar-close]');

  const setAppSidebarOpen = (open) => {
    document.body.classList.toggle('sidebar-open', open);
    if (appSidebarToggle) {
      appSidebarToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
  };

  if (appSidebarToggle) {
    appSidebarToggle.addEventListener('click', () => {
      const next = !document.body.classList.contains('sidebar-open');
      setAppSidebarOpen(next);
    });
  }

  if (appSidebarClose) {
    appSidebarClose.addEventListener('click', () => {
      setAppSidebarOpen(false);
    });
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      setAppSidebarOpen(false);
      setPublicNavOpen(false);
    }
  });

  window.addEventListener('resize', () => {
    if (window.innerWidth > 980) {
      setAppSidebarOpen(false);
    }
  });

  if (sourceInvoice && payeeInput && amountInput) {
    sourceInvoice.addEventListener('change', () => {
      const selected = sourceInvoice.options[sourceInvoice.selectedIndex];
      if (!selected) {
        return;
      }

      const vendorId = selected.getAttribute('data-vendor-id') || '';
      const amount = selected.getAttribute('data-amount') || '';
      if (vendorId !== '') {
        payeeInput.value = vendorId;
      }
      if (amount !== '') {
        amountInput.value = amount;
      }
    });
  }

  if (expenseFiles && proofCount) {
    expenseFiles.addEventListener('change', () => {
      proofCount.value = String(expenseFiles.files ? expenseFiles.files.length : 0);
    });
  }

  const updateMileagePreview = () => {
    if (!distanceKm || !mileageRate || !mileagePreview) {
      return;
    }

    const distance = Number.parseFloat(distanceKm.value || '0');
    const rate = Number.parseFloat(mileageRate.value || '0');
    if (!Number.isFinite(distance) || !Number.isFinite(rate) || distance <= 0 || rate <= 0) {
      mileagePreview.value = '';
      return;
    }

    mileagePreview.value = (distance * rate).toFixed(2);
  };

  if (distanceKm && mileageRate && mileagePreview) {
    distanceKm.addEventListener('input', updateMileagePreview);
    mileageRate.addEventListener('input', updateMileagePreview);
    updateMileagePreview();
  }

  const isStatusHeavyPage = /(approvals|payments|bulk-payout|invoices|tax|inbox|matching|integrations)/.test(window.location.href);
  if (isStatusHeavyPage) {
    setTimeout(() => {
      window.location.reload();
    }, 60000);
  }

  const revealSelector = [
    '.kpi',
    '.section-head',
    '.home-module',
    '.home-flow-grid article',
    '.about-value',
    '.about-principles-grid article',
    '.feature-module',
    '.feature-bands div',
    '.features-integrations-grid article',
    '.pricing-plans .pricing-card',
    '.pricing-notes-grid article',
    '.contact-panel',
    '.contact-check',
    '.explore-card',
    '.integration-provider-card',
  ].join(', ');

  if (document.body.classList.contains('is-public')) {
    // Public pages use card-level reveal; app pages keep data-heavy cards always visible.
    const publicReveal = ['.card:not(.public-nav)', revealSelector].filter(Boolean).join(', ');
    const publicNodes = Array.from(new Set(Array.from(document.querySelectorAll(publicReveal))));
    publicNodes.forEach((node) => node.classList.add('reveal-item'));
  }

  const revealNodes = Array.from(new Set(Array.from(document.querySelectorAll(revealSelector))));
  revealNodes.forEach((node) => node.classList.add('reveal-item'));

  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries, obs) => {
      entries.forEach((entry, index) => {
        if (entry.isIntersecting) {
          const element = entry.target;
          window.setTimeout(() => {
            element.classList.add('is-visible');
          }, Math.min(180, index * 35));
          obs.unobserve(element);
        }
      });
    }, { threshold: 0.03 });

    revealNodes.forEach((node) => observer.observe(node));

    // Failsafe: never leave cards invisible if observer misses a node on long layouts.
    window.setTimeout(() => {
      revealNodes.forEach((node) => node.classList.add('is-visible'));
    }, 900);
  } else {
    revealNodes.forEach((node) => node.classList.add('is-visible'));
  }

  document.querySelectorAll('table').forEach((table) => {
    if (table.parentElement && table.parentElement.classList.contains('table-scroll')) {
      return;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'table-scroll';
    const parent = table.parentNode;
    if (!parent) {
      return;
    }
    parent.insertBefore(wrapper, table);
    wrapper.appendChild(table);
  });

  const autoGlowSelector = [
    '.home-flow-grid article',
    '.about-principles-grid article',
    '.features-integrations-grid article',
    '.pricing-notes-grid article',
    '.feature-bands div',
    '.contact-check',
    '.explore-card',
    '.integration-provider-card',
  ].join(', ');

  document.querySelectorAll(autoGlowSelector).forEach((node) => {
    node.classList.add('glow-card');
    if (!node.hasAttribute('data-glow')) {
      node.setAttribute('data-glow', '');
    }
  });

  document.querySelectorAll('[data-glow]').forEach((card) => {
    card.addEventListener('mousemove', (event) => {
      const rect = card.getBoundingClientRect();
      const x = event.clientX - rect.left;
      const y = event.clientY - rect.top;
      card.style.setProperty('--mx', `${x}px`);
      card.style.setProperty('--my', `${y}px`);
    });
  });

  document.querySelectorAll('.nav-group summary').forEach((summary) => {
    summary.addEventListener('click', () => {
      const details = summary.parentElement;
      if (!(details instanceof HTMLDetailsElement)) {
        return;
      }
      const groups = Array.from(document.querySelectorAll('.nav-group'));
      groups.forEach((entry) => {
        if (entry !== details) {
          entry.removeAttribute('open');
        }
      });
    });
  });

  const toggles = Array.from(document.querySelectorAll('.plan-toggle'));
  if (toggles.length > 0) {
    const prices = Array.from(document.querySelectorAll('.price-value'));
    const cycles = Array.from(document.querySelectorAll('.pricing-amount .cycle'));
    const formatter = new Intl.NumberFormat('en-IN');

    const applyCycle = (cycle) => {
      prices.forEach((node) => {
        const value = node.getAttribute(cycle === 'annual' ? 'data-annual' : 'data-monthly');
        if (!value) {
          return;
        }
        const numeric = Number.parseInt(value, 10);
        if (!Number.isNaN(numeric)) {
          node.textContent = formatter.format(numeric);
        }
      });

      cycles.forEach((node) => {
        node.textContent = cycle === 'annual' ? '/mo (annual)' : '/mo';
      });
    };

    toggles.forEach((button) => {
      button.addEventListener('click', () => {
        const cycle = button.getAttribute('data-plan-cycle') || 'monthly';
        toggles.forEach((entry) => entry.classList.remove('is-active'));
        button.classList.add('is-active');
        applyCycle(cycle);
      });
    });
  }

  const numericTargets = Array.from(document.querySelectorAll('.kpi strong, .about-stats strong'));
  const formatter = new Intl.NumberFormat('en-IN');
  const countTo = (node) => {
    const original = (node.textContent || '').trim();
    if (original === '' || node.dataset.countDone === '1') {
      return;
    }

    const digits = original.replace(/[^0-9]/g, '');
    const target = Number.parseInt(digits, 10);
    if (!Number.isFinite(target) || target <= 0) {
      node.dataset.countDone = '1';
      return;
    }

    const prefixMatch = original.match(/^[^0-9]*/);
    const suffixMatch = original.match(/[^0-9]*$/);
    const prefix = prefixMatch ? prefixMatch[0] : '';
    const suffix = suffixMatch ? suffixMatch[0] : '';
    const duration = 900;
    const start = performance.now();

    const tick = (now) => {
      const elapsed = now - start;
      const progress = Math.min(1, elapsed / duration);
      const eased = 1 - Math.pow(1 - progress, 3);
      const value = Math.round(target * eased);
      node.textContent = `${prefix}${formatter.format(value)}${suffix}`;
      if (progress < 1) {
        requestAnimationFrame(tick);
      } else {
        node.textContent = `${prefix}${formatter.format(target)}${suffix}`;
        node.dataset.countDone = '1';
      }
    };

    requestAnimationFrame(tick);
  };

  if ('IntersectionObserver' in window) {
    const countObserver = new IntersectionObserver((entries, obs) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) {
          return;
        }
        countTo(entry.target);
        obs.unobserve(entry.target);
      });
    }, { threshold: 0.45 });

    numericTargets.forEach((node) => countObserver.observe(node));
  } else {
    numericTargets.forEach((node) => countTo(node));
  }
});
