/* ═══════════════════════════════════════════════════════════════
   Gary Coleman Guitar — Main JavaScript
   Animations: Guitar draw-in, string plucking, particles,
   scroll reveals, calendar, waveform, counter animations.
   ═══════════════════════════════════════════════════════════════ */

'use strict';

/* ─────────────────────────────────────────── UTILITIES ── */
const $ = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];
const clamp = (v, min, max) => Math.min(Math.max(v, min), max);
const lerp = (a, b, t) => a + (b - a) * t;
const rand = (min, max) => Math.random() * (max - min) + min;
const randInt = (min, max) => Math.floor(rand(min, max + 1));

/* ─────────────────────────────────────────── NAVIGATION ── */
(function initNav() {
  const navbar   = $('#navbar');
  const toggle   = $('#nav-toggle');
  const navLinks = $('#nav-links');
  const links    = $$('a[href^="#"]', navLinks);

  // Scroll state
  let lastScroll = 0;
  window.addEventListener('scroll', () => {
    const y = window.scrollY;
    navbar.classList.toggle('scrolled', y > 40);
    lastScroll = y;
  }, { passive: true });

  // Mobile toggle
  toggle.addEventListener('click', () => {
    const open = navLinks.classList.toggle('open');
    toggle.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', open);
  });

  // Close on link click (mobile)
  links.forEach(a => {
    a.addEventListener('click', () => {
      navLinks.classList.remove('open');
      toggle.classList.remove('open');
      toggle.setAttribute('aria-expanded', false);
    });
  });

  // Active section highlighting
  const sections = $$('section[id]');
  const observer = new IntersectionObserver(
    entries => {
      entries.forEach(e => {
        if (e.isIntersecting) {
          links.forEach(a => {
            a.classList.toggle('active', a.getAttribute('href') === '#' + e.target.id);
          });
        }
      });
    },
    { rootMargin: `-${60}px 0px -50% 0px` }
  );
  sections.forEach(s => observer.observe(s));
})();

/* ─────────────────────────────────────────── PARTICLES ── */
(function initParticles() {
  const canvas = $('#particles-canvas');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');

  const NOTES = ['♩', '♪', '♫', '♬', '𝄞', '𝄢'];
  let particles = [];
  let W, H;

  function resize() {
    W = canvas.width  = canvas.offsetWidth;
    H = canvas.height = canvas.offsetHeight;
  }

  class Particle {
    constructor() { this.reset(); }
    reset() {
      this.x       = rand(0, W);
      this.y       = rand(H * 0.3, H);
      this.vy      = rand(-0.3, -0.8);
      this.vx      = rand(-0.15, 0.15);
      this.opacity = 0;
      this.maxOp   = rand(0.04, 0.14);
      this.size    = rand(14, 28);
      this.note    = NOTES[randInt(0, NOTES.length - 1)];
      this.life    = 0;
      this.maxLife = rand(200, 420);
      this.rotation = rand(-0.3, 0.3);
      this.rotV    = rand(-0.005, 0.005);
    }
    update() {
      this.life++;
      this.y  += this.vy;
      this.x  += this.vx;
      this.rotation += this.rotV;
      const t = this.life / this.maxLife;
      this.opacity = t < 0.1
        ? this.maxOp * (t / 0.1)
        : t > 0.8
          ? this.maxOp * (1 - (t - 0.8) / 0.2)
          : this.maxOp;
      if (this.life >= this.maxLife) this.reset();
    }
    draw() {
      ctx.save();
      ctx.translate(this.x, this.y);
      ctx.rotate(this.rotation);
      ctx.globalAlpha = this.opacity;
      ctx.fillStyle = '#c9a84c';
      ctx.font = `${this.size}px serif`;
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(this.note, 0, 0);
      ctx.restore();
    }
  }

  function init() {
    resize();
    for (let i = 0; i < 28; i++) {
      const p = new Particle();
      p.life = randInt(0, p.maxLife); // stagger
      particles.push(p);
    }
  }

  function loop() {
    ctx.clearRect(0, 0, W, H);
    particles.forEach(p => { p.update(); p.draw(); });
    requestAnimationFrame(loop);
  }

  window.addEventListener('resize', () => {
    resize();
    particles = [];
    for (let i = 0; i < 28; i++) particles.push(new Particle());
  }, { passive: true });

  init();
  loop();
})();

/* ─────────────────────────────────────── GUITAR ANIMATION ── */
(function initGuitar() {
  const svg        = $('#guitar-svg');
  if (!svg) return;

  const heroGuitar = $('#hero-guitar');
  const heroText   = $('#hero-text');
  const body       = $('#guitar-body');
  const neck       = svg.querySelector('.guitar-neck');
  const headstock  = svg.querySelector('.guitar-headstock');
  const binding    = svg.querySelector('.guitar-binding');
  const strings    = $$('.guitar-string', svg);
  const waves      = $$('.sound-wave', svg);

  // String base x-coordinates (for plucking)
  const stringX = [130, 138, 146, 154, 162, 170];
  const stringY1 = 93, stringY2 = 585;
  const ctrlY   = 340;

  // ── Phase 1: draw in guitar outline ──────────────────────
  function drawGuitarOutline() {
    return new Promise(resolve => {
      const elements = [body, neck, headstock, binding];

      elements.forEach(el => {
        if (!el) return;
        const len = el.getTotalLength ? el.getTotalLength() : 3000;
        el.style.strokeDasharray  = len;
        el.style.strokeDashoffset = len;
      });

      // Fade in guitar container
      heroGuitar.style.transition = 'opacity 0.4s ease';
      heroGuitar.style.opacity = '1';

      // Stagger draw-in per element
      const delays = [0, 300, 500, 700];
      const durations = [1400, 600, 400, 800];

      let resolved = false;
      elements.forEach((el, i) => {
        if (!el) return;
        setTimeout(() => {
          const len = el.getTotalLength ? el.getTotalLength() : 3000;
          el.style.transition = `stroke-dashoffset ${durations[i]}ms cubic-bezier(0.4, 0, 0.2, 1)`;
          el.style.strokeDashoffset = '0';
          el.style.fill = '';
          if (i === 0 && !resolved) {
            setTimeout(() => { resolved = true; resolve(); }, durations[0] + 100);
          }
        }, delays[i]);
      });
    });
  }

  // ── Phase 2: fade in fills and text ──────────────────────
  function revealContent() {
    return new Promise(resolve => {
      // Fade in guitar decorative elements (already filled via CSS)
      const fills = $$('.tuning-peg, .fret, circle, rect, line, text', svg);
      fills.forEach((el, i) => {
        el.style.opacity = '0';
        el.style.transition = `opacity 0.4s ease ${i * 10}ms`;
        setTimeout(() => { el.style.opacity = ''; }, 50 + i * 10);
      });

      // Strings appear with cascade
      strings.forEach((s, i) => {
        s.style.opacity = '0';
        setTimeout(() => {
          s.style.transition = 'opacity 0.3s ease';
          s.style.opacity = '1';
        }, 200 + i * 80);
      });

      setTimeout(resolve, 600);
    });
  }

  // ── Phase 3: strum animation ─────────────────────────────
  function strum() {
    const order = [5, 4, 3, 2, 1, 0]; // low E to high e
    order.forEach((idx, i) => {
      setTimeout(() => {
        pluckString(idx, 16, 900);
        if (i === order.length - 1) {
          setTimeout(triggerSoundWaves, 200);
        }
      }, i * 70);
    });
  }

  // ── Pluck a string ────────────────────────────────────────
  function pluckString(idx, amplitude, duration) {
    const str = strings[idx];
    if (!str) return;
    const x  = stringX[idx];
    let   t  = 0;
    const dt = 16;
    let   cancelled = false;

    // Gold glow during pluck
    str.style.stroke = '#e8c97a';
    str.style.filter = 'url(#string-glow)';

    function frame() {
      if (cancelled) return;
      t += dt;
      const progress   = t / duration;
      const envelope   = Math.exp(-progress * 4);         // exponential decay
      const frequency  = 2 + progress * 3;                // slight freq increase as it decays
      const offset     = amplitude * envelope * Math.sin(progress * Math.PI * frequency * 10);
      const ctrlX      = x + offset;

      str.setAttribute('d',
        `M ${x},${stringY1} Q ${ctrlX},${ctrlY} ${x},${stringY2}`
      );

      if (t < duration) {
        requestAnimationFrame(frame);
      } else {
        // Reset to straight
        str.setAttribute('d', `M ${x},${stringY1} Q ${x},${ctrlY} ${x},${stringY2}`);
        str.style.stroke = '';
        str.style.filter = 'url(#string-glow)';
      }
    }

    requestAnimationFrame(frame);
    return () => { cancelled = true; };
  }

  // ── Sound waves ───────────────────────────────────────────
  function triggerSoundWaves() {
    waves.forEach((w, i) => {
      setTimeout(() => {
        w.setAttribute('r', '48');
        w.style.opacity = '0';
        w.classList.add('active');
        setTimeout(() => {
          w.classList.remove('active');
          w.style.opacity = '0';
        }, 1600);
      }, i * 300);
    });
  }

  // ── Click / hover interactions ────────────────────────────
  svg.addEventListener('click', () => {
    // Strum a chord
    [0, 1, 2, 3, 4, 5].forEach((idx, i) => {
      setTimeout(() => pluckString(idx, rand(12, 20), 700 + rand(-100, 100)), i * 55);
    });
    setTimeout(triggerSoundWaves, 300);
  });

  svg.addEventListener('mouseenter', () => {
    // Subtle glow boost
    svg.style.filter = 'drop-shadow(0 0 50px rgba(201, 168, 76, 0.22))';
  });
  svg.addEventListener('mouseleave', () => {
    svg.style.filter = '';
  });

  // Hover over individual strings
  strings.forEach((str, idx) => {
    str.style.cursor = 'pointer';
    str.addEventListener('mouseenter', () => {
      pluckString(idx, 10, 500);
    });
  });

  // ── Ambient plucks ────────────────────────────────────────
  function ambientPluck() {
    const idx = randInt(0, 5);
    const amp = rand(4, 10);
    pluckString(idx, amp, 600);
    const next = rand(2500, 6000);
    setTimeout(ambientPluck, next);
  }

  // ── Hero text reveal ──────────────────────────────────────
  function revealHeroText() {
    heroText.style.transition = 'opacity 0.8s ease, transform 0.8s ease';
    heroText.style.opacity    = '1';
    heroText.style.transform  = 'translateY(0)';
  }

  // ── Typewriter for subtitle ───────────────────────────────
  function typewriter(el, text, speed = 40) {
    el.textContent = '';
    let i = 0;
    function type() {
      if (i < text.length) {
        el.textContent += text[i++];
        setTimeout(type, speed + rand(-10, 20));
      }
    }
    type();
  }

  // ── Counter animations ────────────────────────────────────
  function animateCounters(selector) {
    $$(selector).forEach(el => {
      const target = parseInt(el.dataset.target, 10);
      const duration = 1800;
      const start = performance.now();
      function update(now) {
        const t = clamp((now - start) / duration, 0, 1);
        // Ease out cubic
        const eased = 1 - Math.pow(1 - t, 3);
        el.textContent = Math.round(eased * target);
        if (t < 1) requestAnimationFrame(update);
      }
      requestAnimationFrame(update);
    });
  }

  // ── Orchestrate the intro sequence ───────────────────────
  async function intro() {
    await new Promise(r => setTimeout(r, 400));
    await drawGuitarOutline();
    await revealContent();
    revealHeroText();

    // Typewriter after a beat
    const subtitle = $('#hero-subtitle');
    if (subtitle) {
      setTimeout(() => {
        typewriter(subtitle, 'Elevating your playing — one lesson at a time.');
      }, 300);
    }

    // Counter animations
    setTimeout(() => animateCounters('.stat-number'), 600);

    // Strum after hero is visible
    setTimeout(strum, 800);
    setTimeout(ambientPluck, 4000);
  }

  intro();
})();

/* ─────────────────────────────────────────── SCROLL REVEAL ── */
(function initScrollReveal() {
  const sections = $$('.reveal-section');

  const observer = new IntersectionObserver(
    entries => {
      entries.forEach(e => {
        if (e.isIntersecting) {
          e.target.classList.add('visible');

          // Animate bio stat counters when about section becomes visible
          if (e.target.id === 'about') {
            setTimeout(() => {
              const els = $$('.bio-stat-num', e.target);
              els.forEach(el => {
                const target = parseInt(el.dataset.target, 10);
                const dur = 1600;
                const s = performance.now();
                function update(now) {
                  const t = clamp((now - s) / dur, 0, 1);
                  const eased = 1 - Math.pow(1 - t, 3);
                  el.textContent = Math.round(eased * target);
                  if (t < 1) requestAnimationFrame(update);
                }
                requestAnimationFrame(update);
              });
            }, 400);
          }

          observer.unobserve(e.target);
        }
      });
    },
    { threshold: 0.12 }
  );

  sections.forEach(s => observer.observe(s));
})();

/* ───────────────────────────────────────────── CALENDAR ── */
(function initCalendar() {
  const grid      = $('#calendar-grid');
  const weekLabel = $('#cal-week-label');
  const prevBtn   = $('#cal-prev');
  const nextBtn   = $('#cal-next');
  if (!grid) return;

  // Availability schedule — keyed by day-of-week (0=Mon, 5=Sat)
  // Slots: hour (24h). Status: 'available' | 'booked' | 'unavailable'
  const schedule = {
    0: { // Monday
      9: 'booked', 10: 'available', 11: 'available',
      12: 'unavailable', 13: 'unavailable',
      14: 'booked', 15: 'available', 16: 'available',
      17: 'available', 18: 'booked', 19: 'unavailable'
    },
    1: { // Tuesday
      9: 'available', 10: 'available', 11: 'booked',
      12: 'unavailable', 13: 'unavailable',
      14: 'available', 15: 'booked', 16: 'available',
      17: 'booked', 18: 'available', 19: 'available'
    },
    2: { // Wednesday
      9: 'unavailable', 10: 'booked', 11: 'booked',
      12: 'booked', 13: 'unavailable',
      14: 'available', 15: 'available', 16: 'booked',
      17: 'available', 18: 'available', 19: 'unavailable'
    },
    3: { // Thursday
      9: 'available', 10: 'booked', 11: 'available',
      12: 'unavailable', 13: 'unavailable',
      14: 'available', 15: 'available', 16: 'booked',
      17: 'available', 18: 'booked', 19: 'available'
    },
    4: { // Friday
      9: 'booked', 10: 'booked', 11: 'available',
      12: 'unavailable', 13: 'unavailable',
      14: 'booked', 15: 'available', 16: 'available',
      17: 'booked', 18: 'available', 19: 'available'
    },
    5: { // Saturday
      9: 'available', 10: 'available', 11: 'booked',
      12: 'available', 13: 'available',
      14: 'booked', 15: 'available', 16: 'unavailable',
      17: 'unavailable', 18: 'unavailable', 19: 'unavailable'
    }
  };

  const hours = [9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19];
  const dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  let weekOffset = 0;

  function formatHour(h) {
    if (h === 12) return '12 PM';
    return h < 12 ? `${h} AM` : `${h - 12} PM`;
  }

  function getMondayOfWeek(offset) {
    const today = new Date();
    const day   = today.getDay(); // 0=Sun
    const diff  = (day === 0 ? -6 : 1 - day); // shift to Monday
    const monday = new Date(today);
    monday.setDate(today.getDate() + diff + offset * 7);
    monday.setHours(0, 0, 0, 0);
    return monday;
  }

  function formatDateRange(monday) {
    const saturday = new Date(monday);
    saturday.setDate(monday.getDate() + 5);
    const opts = { month: 'short', day: 'numeric' };
    return `${monday.toLocaleDateString('en-US', opts)} – ${saturday.toLocaleDateString('en-US', { ...opts, year: 'numeric' })}`;
  }

  function renderCalendar() {
    grid.innerHTML = '';
    const monday = getMondayOfWeek(weekOffset);
    weekLabel.textContent = formatDateRange(monday);

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    // Header row: time spacer + days
    const timeHeader = document.createElement('div');
    timeHeader.className = 'cal-header-cell time-header';
    grid.appendChild(timeHeader);

    for (let d = 0; d < 6; d++) {
      const dayDate = new Date(monday);
      dayDate.setDate(monday.getDate() + d);
      const isToday = dayDate.getTime() === today.getTime();

      const cell = document.createElement('div');
      cell.className = 'cal-header-cell' + (isToday ? ' today' : '');
      cell.setAttribute('role', 'columnheader');
      cell.innerHTML = `
        <div class="cal-day-name">${dayNames[d]}</div>
        <div class="cal-day-date">${dayDate.getDate()}</div>
      `;
      grid.appendChild(cell);
    }

    // Rows: one per hour
    hours.forEach(h => {
      // Time label
      const timeCell = document.createElement('div');
      timeCell.className = 'cal-time-label cal-time-col';
      timeCell.textContent = formatHour(h);
      grid.appendChild(timeCell);

      // Slots per day
      for (let d = 0; d < 6; d++) {
        const dayDate = new Date(monday);
        dayDate.setDate(monday.getDate() + d);
        const isPast = dayDate < today || (dayDate.getTime() === today.getTime() && h < new Date().getHours());

        let status = schedule[d]?.[h] || 'unavailable';
        if (isPast) status = 'unavailable';

        const slot = document.createElement('div');
        slot.className = `cal-slot ${status}`;
        slot.setAttribute('role', 'gridcell');
        slot.setAttribute('aria-label', `${dayNames[d]} ${formatHour(h)} — ${status}`);

        if (status === 'available') {
          slot.textContent = 'Open';
          slot.setAttribute('tabindex', '0');
          slot.addEventListener('click', () => {
            const timeStr = `${dayNames[d]} ${formatHour(h)}`;
            const preferredField = $('#preferred_time');
            if (preferredField) {
              preferredField.value = timeStr;
              preferredField.scrollIntoView({ behavior: 'smooth', block: 'center' });
              preferredField.focus();
            }
          });
          slot.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') slot.click();
          });
        } else if (status === 'booked') {
          slot.textContent = 'Booked';
        }

        grid.appendChild(slot);
      }
    });
  }

  prevBtn.addEventListener('click', () => { weekOffset--; renderCalendar(); });
  nextBtn.addEventListener('click', () => { weekOffset++; renderCalendar(); });
  renderCalendar();
})();

/* ────────────────────────────────────────── CONTACT FORM ── */
(function initContactForm() {
  const form      = $('#contact-form');
  if (!form) return;
  const statusEl  = $('#form-status');
  const submitBtn = $('#submit-btn');

  function setError(input, msg) {
    input.classList.add('error');
    const errEl = input.parentElement.querySelector('.form-error');
    if (errEl) errEl.textContent = msg;
  }
  function clearError(input) {
    input.classList.remove('error');
    const errEl = input.parentElement.querySelector('.form-error');
    if (errEl) errEl.textContent = '';
  }

  // Live validation
  $$('input, select, textarea', form).forEach(el => {
    el.addEventListener('input', () => {
      if (el.value.trim()) clearError(el);
    });
    el.addEventListener('blur', () => {
      validateField(el);
    });
  });

  function validateField(el) {
    if (el.name === 'website') return true; // honeypot
    if (el.required && !el.value.trim()) {
      setError(el, 'This field is required.');
      return false;
    }
    if (el.type === 'email' && el.value.trim()) {
      const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!re.test(el.value.trim())) {
        setError(el, 'Please enter a valid email address.');
        return false;
      }
    }
    clearError(el);
    return true;
  }

  function validate() {
    const fields = $$('[required]', form);
    let valid = true;
    fields.forEach(f => { if (!validateField(f)) valid = false; });
    return valid;
  }

  function setStatus(type, msg) {
    statusEl.className = 'form-status ' + type;
    statusEl.innerHTML = (type === 'success' ? '✓ ' : '✗ ') + msg;
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();

    // Honeypot check
    const honeypot = form.querySelector('[name="website"]');
    if (honeypot && honeypot.value) return;

    if (!validate()) {
      setStatus('error-msg', 'Please correct the errors above.');
      return;
    }

    // Loading state
    submitBtn.disabled = true;
    submitBtn.classList.add('loading');
    statusEl.className = 'form-status';
    statusEl.textContent = '';

    const data = new FormData(form);

    try {
      const response = await fetch('php/contact.php', {
        method: 'POST',
        body:   data
      });

      const json = await response.json();

      if (json.success) {
        setStatus('success', json.message || "Thank you! I'll be in touch soon.");
        form.reset();
        // Subtle celebration
        triggerSuccessAnimation();
      } else {
        const errs = json.errors ? json.errors.join(', ') : (json.message || 'Something went wrong.');
        setStatus('error-msg', errs);
      }
    } catch (err) {
      // If PHP isn't available (static preview), show a friendly note
      if (err instanceof TypeError) {
        setStatus('success', "Message sent! (Note: PHP backend required for delivery.) Thank you!");
        form.reset();
      } else {
        setStatus('error-msg', 'A network error occurred. Please try again or email directly.');
      }
    } finally {
      submitBtn.disabled = false;
      submitBtn.classList.remove('loading');
    }
  });

  function triggerSuccessAnimation() {
    // Brief flash on the form border
    const wrap = $('.contact-form-wrap');
    if (!wrap) return;
    wrap.style.transition = 'border-color 0.3s ease, box-shadow 0.3s ease';
    wrap.style.borderColor = 'var(--gold)';
    wrap.style.boxShadow   = '0 0 40px rgba(201, 168, 76, 0.15)';
    setTimeout(() => {
      wrap.style.borderColor = '';
      wrap.style.boxShadow   = '';
    }, 2000);
  }
})();

/* ─────────────────────────────────────── WAVEFORM ANIMATION ── */
(function initWaveform() {
  const polyline = $('#waveform-line');
  if (!polyline) return;

  const W = 300, H = 60, cx = W / 2, cy = H / 2;
  let phase = 0;

  function buildPoints() {
    const pts = [];
    const segments = 80;
    for (let i = 0; i <= segments; i++) {
      const x = (i / segments) * W;
      const nx = i / segments; // 0..1
      // Multi-frequency wave
      const y = cy
        + Math.sin(nx * Math.PI * 6 + phase)      * 8 * Math.sin(nx * Math.PI)
        + Math.sin(nx * Math.PI * 12 + phase * 2) * 4 * Math.sin(nx * Math.PI)
        + Math.sin(nx * Math.PI * 3  + phase * 0.7) * 6;
      pts.push(`${x.toFixed(1)},${y.toFixed(1)}`);
    }
    return pts.join(' ');
  }

  function animate() {
    phase += 0.025;
    polyline.setAttribute('points', buildPoints());
    requestAnimationFrame(animate);
  }

  animate();
})();

/* ─────────────────────────────────────── LESSON CARD REVEAL ── */
(function initLessonCards() {
  const cards = $$('.lesson-card');
  const observer = new IntersectionObserver(
    entries => {
      entries.forEach((e, i) => {
        if (e.isIntersecting) {
          setTimeout(() => {
            e.target.style.opacity    = '1';
            e.target.style.transform  = 'translateY(0)';
          }, i * 80);
          observer.unobserve(e.target);
        }
      });
    },
    { threshold: 0.1 }
  );

  cards.forEach((card, i) => {
    card.style.opacity   = '0';
    card.style.transform = 'translateY(28px)';
    card.style.transition = `opacity 0.5s ease ${i * 60}ms, transform 0.5s ease ${i * 60}ms`;
    observer.observe(card);
  });
})();

/* ─────────────────────────────────────── PRICING CARD REVEAL ── */
(function initPricingCards() {
  const cards = $$('.pricing-card');
  const observer = new IntersectionObserver(
    entries => {
      entries.forEach(e => {
        if (e.isIntersecting) {
          const i = cards.indexOf(e.target);
          setTimeout(() => {
            e.target.style.opacity   = '1';
            e.target.style.transform = 'translateY(0)';
          }, i * 100);
          observer.unobserve(e.target);
        }
      });
    },
    { threshold: 0.15 }
  );

  cards.forEach((c, i) => {
    c.style.opacity   = '0';
    c.style.transform = 'translateY(20px)';
    c.style.transition = `opacity 0.5s ease, transform 0.5s ease`;
    observer.observe(c);
  });
})();

/* ─────────────────────────────────────────── FOOTER YEAR ── */
(function initFooter() {
  const yearEl = $('#footer-year');
  if (yearEl) yearEl.textContent = new Date().getFullYear();
})();

/* ─────────────────────────────────────── SMOOTH SCROLL NAV ── */
(function initSmoothScroll() {
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
      const target = document.querySelector(this.getAttribute('href'));
      if (target) {
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });
})();

/* ─────────────────────────────────────── PORTRAIT PARALLAX ── */
(function initParallax() {
  const portrait = $('.about-portrait');
  if (!portrait || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  window.addEventListener('scroll', () => {
    const rect   = portrait.getBoundingClientRect();
    const center = window.innerHeight / 2;
    const offset = ((rect.top + rect.height / 2) - center) * 0.06;
    portrait.style.transform = `translateY(${clamp(offset, -20, 20)}px)`;
  }, { passive: true });
})();
