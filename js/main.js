/* ═══════════════════════════════════════════════════════════════
   Gary Coleman Guitar — Main JavaScript
   Features:
     - Karplus-Strong guitar string synthesis (Web Audio API)
     - Interactive SVG guitar: hover strings → play notes, click → strum
     - String vibration physics animation (damped oscillation)
     - Floating music note particles (canvas)
     - Scroll reveal + counter animations
     - Lessons accordion
     - Interactive calendar
     - Animated waveform (contact section)
     - Contact form with validation + PHP AJAX submit
   ═══════════════════════════════════════════════════════════════ */

'use strict';

/* ─────────────────────────────────────────── UTILITIES ── */
const $ = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];
const clamp = (v, min, max) => Math.min(Math.max(v, min), max);
const rand = (min, max) => Math.random() * (max - min) + min;
const randInt = (min, max) => Math.floor(rand(min, max + 1));

/* ═══════════════════════════════════════ GUITAR AUDIO ══
 * Karplus-Strong plucked string synthesis.
 * Produces realistic guitar-like tones entirely in the browser.
 * AudioContext is unlocked on first user gesture (click/touch).
 * ════════════════════════════════════════════════════════ */
const GuitarAudio = (() => {
  let ctx         = null;
  let masterGain  = null;
  let active      = false;

  // Standard tuning: index 0 = high e (str-1) → index 5 = low E (str-6)
  // G major chord voicing (str-1 high e → str-6 low E)
  // G4, B3, G3, D3, B2, G2
  const FREQS  = [392.00, 246.94, 196.00, 146.83, 123.47, 98.00];
  // Lower strings sustain longer (higher damping factor)
  const DAMP   = [0.9930, 0.9943, 0.9955, 0.9963, 0.9971, 0.9978];
  // Duration in seconds per string (low strings ring longer)
  const DURATIONS = [1.8, 2.0, 2.2, 2.5, 2.8, 3.2];

  function setup() {
    if (ctx) return;
    try {
      ctx = new (window.AudioContext || window.webkitAudioContext)();

      // Master output gain
      masterGain = ctx.createGain();
      masterGain.gain.value = 0.55;

      // Warm low-pass filter (remove harsh highs)
      const warmth = ctx.createBiquadFilter();
      warmth.type = 'lowpass';
      warmth.frequency.value = 4800;
      warmth.Q.value = 0.5;

      masterGain.connect(warmth);
      warmth.connect(ctx.destination);
    } catch (e) {
      console.warn('Web Audio API unavailable:', e.message);
    }
  }

  /** Call on first user click/touch to unlock AudioContext */
  function unlock() {
    setup();
    if (!ctx) return;
    if (ctx.state === 'suspended') {
      ctx.resume().then(() => { active = true; });
    } else {
      active = true;
    }
  }

  /**
   * Synthesise a plucked guitar string.
   * @param {number} stringIdx  0 = high e, 5 = low E
   * @param {number} velocity   0.0 – 1.0 (louder = harder pick)
   */
  function pluck(stringIdx, velocity = 0.8) {
    if (!active || !ctx || ctx.state !== 'running') return;

    const freq = FREQS[stringIdx] ?? 220;
    const damp = DAMP[stringIdx]  ?? 0.996;
    const dur  = DURATIONS[stringIdx] ?? 2.5;
    const sr   = ctx.sampleRate;

    // Delay line length N ≈ sample rate / fundamental frequency
    const N    = Math.max(2, Math.round(sr / freq));
    const size = Math.min(Math.ceil(sr * dur), sr * 4); // cap at 4 s

    const buffer = ctx.createBuffer(1, size, sr);
    const data   = buffer.getChannelData(0);

    // ── Excitation: shaped white noise (more pluck-like than flat noise) ──
    for (let i = 0; i < N; i++) {
      // Triangle window: louder in the middle → brighter attack
      const env = i < N / 2 ? (2 * i / N) : (2 - 2 * i / N);
      data[i] = (Math.random() * 2 - 1) * velocity * (0.65 + 0.35 * env);
    }

    // ── Karplus-Strong feedback loop ──
    data[N] = damp * data[0];
    for (let i = N + 1; i < size; i++) {
      // Low-pass filter embedded in feedback: average adjacent samples
      data[i] = damp * 0.5 * (data[i - N] + data[i - N - 1]);
    }

    const source = ctx.createBufferSource();
    source.buffer = buffer;

    const gain = ctx.createGain();
    gain.gain.setValueAtTime(1.0, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + dur);

    source.connect(gain);
    gain.connect(masterGain);
    source.start(ctx.currentTime);
    source.stop(ctx.currentTime + dur + 0.05);
    source.addEventListener('ended', () => {
      try { gain.disconnect(); } catch (_) {}
    });
  }

  return { unlock, pluck, isActive: () => active };
})();

/* ─────────────────────────────────────────── NAVIGATION ── */
(function initNav() {
  const navbar   = $('#navbar');
  const toggle   = $('#nav-toggle');
  const navLinks = $('#nav-links');
  const links    = $$('a[href^="#"]', navLinks);

  window.addEventListener('scroll', () => {
    navbar.classList.toggle('scrolled', window.scrollY > 40);
  }, { passive: true });

  toggle.addEventListener('click', () => {
    const open = navLinks.classList.toggle('open');
    toggle.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', String(open));
  });

  links.forEach(a => {
    a.addEventListener('click', () => {
      navLinks.classList.remove('open');
      toggle.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
    });
  });

  // Active nav link tracking
  const sections = $$('section[id]');
  const secObserver = new IntersectionObserver(
    entries => entries.forEach(e => {
      if (e.isIntersecting) {
        links.forEach(a =>
          a.classList.toggle('active', a.getAttribute('href') === '#' + e.target.id)
        );
      }
    }),
    { rootMargin: `-${60}px 0px -50% 0px` }
  );
  sections.forEach(s => secObserver.observe(s));
})();

/* ─────────────────────────────────────────── PARTICLES ── */
(function initParticles() {
  const canvas = $('#particles-canvas');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const NOTES = ['♩', '♪', '♫', '♬', '𝄞'];
  let W, H, particles = [];

  function resize() {
    W = canvas.width  = canvas.offsetWidth;
    H = canvas.height = canvas.offsetHeight;
  }

  class Particle {
    constructor() { this.reset(); }
    reset() {
      this.x       = rand(0, W);
      this.y       = rand(H * 0.3, H);
      this.vy      = rand(-0.28, -0.72);
      this.vx      = rand(-0.12, 0.12);
      this.opacity = 0;
      this.maxOp   = rand(0.04, 0.13);
      this.size    = rand(13, 26);
      this.note    = NOTES[randInt(0, NOTES.length - 1)];
      this.life    = 0;
      this.maxLife = rand(200, 420);
      this.rotation = rand(-0.3, 0.3);
      this.rotV    = rand(-0.005, 0.005);
    }
    update() {
      this.life++;
      this.y += this.vy;
      this.x += this.vx;
      this.rotation += this.rotV;
      const t = this.life / this.maxLife;
      this.opacity = t < 0.1 ? this.maxOp * (t / 0.1)
        : t > 0.8 ? this.maxOp * (1 - (t - 0.8) / 0.2)
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
      p.life = randInt(0, p.maxLife);
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

/* ──────────────────────────────────── GUITAR INTERACTION ── */
(function initGuitar() {
  const svg        = $('#guitar-svg');
  if (!svg) return;

  const heroGuitar = $('#hero-guitar');
  const heroText   = $('#hero-text');
  const hint       = $('#guitar-hint');
  const bodyPath   = $('#guitar-body');
  const neckPath   = svg.querySelector('.guitar-neck');
  const headPath   = svg.querySelector('.guitar-headstock');
  const binding    = svg.querySelector('.guitar-binding');
  const strings    = $$('.guitar-string', svg);
  const waves      = $$('.sound-wave', svg);

  // String x-coordinates (matching SVG path data)
  const STR_X  = [130, 138, 146, 154, 162, 170];
  const STR_Y1 = 93, STR_Y2 = 585, CTRL_Y = 340;

  // ── Pluck a string (visual only) ─────────────────────────
  // Returns a cancel function.
  function pluckString(idx, amplitude, duration) {
    const str = strings[idx];
    if (!str) return () => {};

    const x = STR_X[idx];
    let t = 0, cancelled = false;

    // Briefly highlight the string
    str.style.stroke = '#e8c97a';

    function frame() {
      if (cancelled) return;
      t += 16;
      const progress  = t / duration;
      const envelope  = Math.exp(-progress * 4);
      const frequency = 2 + progress * 3;
      const offset    = amplitude * envelope * Math.sin(progress * Math.PI * frequency * 10);
      str.setAttribute('d', `M ${x},${STR_Y1} Q ${x + offset},${CTRL_Y} ${x},${STR_Y2}`);
      if (t < duration) {
        requestAnimationFrame(frame);
      } else {
        str.setAttribute('d', `M ${x},${STR_Y1} Q ${x},${CTRL_Y} ${x},${STR_Y2}`);
        str.style.stroke = '';
      }
    }
    requestAnimationFrame(frame);
    return () => { cancelled = true; };
  }

  // ── Sound waves from sound hole ───────────────────────────
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

  // ── Strum all strings (low E → high e downstroke) ────────
  function strum(withAudio = false) {
    // Downstroke order: 5→4→3→2→1→0 (low E to high e)
    [5, 4, 3, 2, 1, 0].forEach((idx, i) => {
      setTimeout(() => {
        const vel = 0.85 - i * 0.05; // slightly quieter as strum progresses
        pluckString(idx, 17 - i, 900 + rand(-80, 80));
        if (withAudio) GuitarAudio.pluck(idx, vel);
      }, i * 65);
    });
    setTimeout(triggerSoundWaves, 350);
  }

  // ── Per-string interaction: hover + click/touch ───────────
  strings.forEach((str, idx) => {
    str.style.cursor = 'pointer';

    // Hover: light pluck (no audio unless already unlocked)
    str.addEventListener('mouseenter', () => {
      pluckString(idx, 9, 500);
      GuitarAudio.pluck(idx, 0.32); // only plays if ctx is running
    });

    // Click/tap: strong pluck + audio unlock + note
    str.addEventListener('pointerdown', e => {
      e.stopPropagation(); // don't bubble to svg body handler
      GuitarAudio.unlock();
      pluckString(idx, 18, 850);
      GuitarAudio.pluck(idx, 0.88);
    });
  });

  // ── Click on guitar body (not a string) → strum ──────────
  svg.addEventListener('click', e => {
    if (e.target.closest('.guitar-string')) return; // handled above
    GuitarAudio.unlock();
    strum(true);
    // Show hint the first time
    if (hint && !hint.classList.contains('shown')) {
      hint.classList.add('visible', 'shown');
      setTimeout(() => hint.classList.remove('visible'), 4000);
    }
  });

  // Touch: same as click for body
  svg.addEventListener('touchend', e => {
    if (e.target.closest('.guitar-string')) return;
    GuitarAudio.unlock();
  }, { passive: true });

  // Hover glow
  svg.addEventListener('mouseenter', () => {
    svg.style.filter = 'drop-shadow(0 0 50px rgba(201, 168, 76, 0.2))';
    if (hint && !hint.classList.contains('shown')) {
      hint.classList.add('visible');
    }
  });
  svg.addEventListener('mouseleave', () => {
    svg.style.filter = '';
    if (hint && !hint.classList.contains('shown')) {
      hint.classList.remove('visible');
    }
  });


  // ── Guitar draw-in on load ────────────────────────────────
  function drawOutline() {
    return new Promise(resolve => {
      const elements  = [bodyPath, neckPath, headPath, binding].filter(Boolean);
      const delays    = [0, 300, 500, 700];
      const durations = [1400, 600, 400, 800];

      elements.forEach(el => {
        const len = el.getTotalLength ? el.getTotalLength() : 3000;
        el.style.strokeDasharray  = len;
        el.style.strokeDashoffset = len;
      });

      heroGuitar.style.transition = 'opacity 0.4s ease';
      heroGuitar.style.opacity = '1';

      elements.forEach((el, i) => {
        setTimeout(() => {
          const len = el.getTotalLength ? el.getTotalLength() : 3000;
          el.style.transition = `stroke-dashoffset ${durations[i]}ms cubic-bezier(0.4,0,0.2,1)`;
          el.style.strokeDashoffset = '0';
          if (i === 0) setTimeout(resolve, durations[0] + 100);
        }, delays[i]);
      });
    });
  }

  function revealDecorations() {
    strings.forEach((s, i) => {
      s.style.opacity = '0';
      setTimeout(() => {
        s.style.transition = 'opacity 0.3s ease';
        s.style.opacity = '1';
      }, 200 + i * 80);
    });
    return new Promise(r => setTimeout(r, 600));
  }

  // ── Hero text typewriter ───────────────────────────────────
  function typewriter(el, text, speed = 42) {
    el.textContent = '';
    let i = 0;
    (function type() {
      if (i < text.length) {
        el.textContent += text[i++];
        setTimeout(type, speed + rand(-12, 18));
      }
    })();
  }

  // ── Counter animation ──────────────────────────────────────
  function animateCounters(selector) {
    $$(selector).forEach(el => {
      const target = parseInt(el.dataset.target, 10);
      const start  = performance.now();
      const dur    = 1800;
      (function update(now) {
        const eased = 1 - Math.pow(1 - clamp((now - start) / dur, 0, 1), 3);
        el.textContent = Math.round(eased * target);
        if (eased < 1) requestAnimationFrame(update);
      })(performance.now());
    });
  }

  // ── Intro sequence ─────────────────────────────────────────
  async function intro() {
    await new Promise(r => setTimeout(r, 350));
    await drawOutline();
    await revealDecorations();

    // Reveal hero text
    heroText.style.transition = 'opacity 0.8s ease, transform 0.8s ease';
    heroText.style.opacity    = '1';
    heroText.style.transform  = 'translateY(0)';

    const subtitle = $('#hero-subtitle');
    if (subtitle) {
      setTimeout(() => typewriter(subtitle, 'Elevating your playing — one lesson at a time.'), 300);
    }

    setTimeout(() => animateCounters('.stat-number'), 500);
    // Silent visual strum on load (no audio — no user gesture yet)
    setTimeout(() => strum(false), 700);
  }

  intro();
})();

/* ─────────────────────────────────────────── SCROLL REVEAL ── */
(function initScrollReveal() {
  const observer = new IntersectionObserver(
    entries => entries.forEach(e => {
      if (!e.isIntersecting) return;
      e.target.classList.add('visible');

      // Animate bio stats when about section appears
      if (e.target.id === 'about') {
        setTimeout(() => {
          $$('.bio-stat-num', e.target).forEach(el => {
            const target = parseInt(el.dataset.target, 10);
            const start  = performance.now();
            (function update(now) {
              const eased = 1 - Math.pow(1 - clamp((now - start) / 1600, 0, 1), 3);
              el.textContent = Math.round(eased * target);
              if (eased < 1) requestAnimationFrame(update);
            })(performance.now());
          });
        }, 400);
      }

      observer.unobserve(e.target);
    }),
    { threshold: 0.1 }
  );

  $$('.reveal-section').forEach(s => observer.observe(s));
})();

/* ─────────────────────────────────────── LESSONS ACCORDION ── */
(function initAccordion() {
  const items = $$('.la-item');

  function closeItem(item) {
    const body = item.querySelector('.la-body');
    const btn  = item.querySelector('.la-header');
    if (!body || !item.classList.contains('open')) return;
    // Animate from current height → 0
    body.style.height = body.scrollHeight + 'px';
    body.offsetHeight; // force reflow
    body.style.height = '0';
    item.classList.remove('open');
    btn.setAttribute('aria-expanded', 'false');
  }

  function openItem(item) {
    const body = item.querySelector('.la-body');
    const btn  = item.querySelector('.la-header');
    if (!body || item.classList.contains('open')) return;
    body.style.height = body.scrollHeight + 'px';
    item.classList.add('open');
    btn.setAttribute('aria-expanded', 'true');
    // After transition, set to auto so it can resize if needed
    body.addEventListener('transitionend', () => {
      if (item.classList.contains('open')) body.style.height = 'auto';
    }, { once: true });
  }

  items.forEach(item => {
    const btn = item.querySelector('.la-header');
    if (!btn) return;

    btn.addEventListener('click', () => {
      const isOpen = item.classList.contains('open');
      items.forEach(closeItem); // close all
      if (!isOpen) openItem(item);
    });
  });

  // Open first item on load
  if (items[0]) {
    const firstBody = items[0].querySelector('.la-body');
    items[0].classList.add('open');
    items[0].querySelector('.la-header')?.setAttribute('aria-expanded', 'true');
    if (firstBody) firstBody.style.height = 'auto';
  }
})();

/* ───────────────────────────────────────────── CALENDAR ── */
(function initCalendar() {
  const grid      = $('#calendar-grid');
  const weekLabel = $('#cal-week-label');
  const prevBtn   = $('#cal-prev');
  const nextBtn   = $('#cal-next');
  if (!grid) return;

  // Availability template by day-of-week (0=Mon … 5=Sat)
  const schedule = {
    0: { 9:'booked',10:'available',11:'available',12:'unavailable',13:'unavailable',14:'booked',15:'available',16:'available',17:'available',18:'booked',19:'unavailable' },
    1: { 9:'available',10:'available',11:'booked',12:'unavailable',13:'unavailable',14:'available',15:'booked',16:'available',17:'booked',18:'available',19:'available' },
    2: { 9:'unavailable',10:'booked',11:'booked',12:'booked',13:'unavailable',14:'available',15:'available',16:'booked',17:'available',18:'available',19:'unavailable' },
    3: { 9:'available',10:'booked',11:'available',12:'unavailable',13:'unavailable',14:'available',15:'available',16:'booked',17:'available',18:'booked',19:'available' },
    4: { 9:'booked',10:'booked',11:'available',12:'unavailable',13:'unavailable',14:'booked',15:'available',16:'available',17:'booked',18:'available',19:'available' },
    5: { 9:'available',10:'available',11:'booked',12:'available',13:'available',14:'booked',15:'available',16:'unavailable',17:'unavailable',18:'unavailable',19:'unavailable' }
  };

  const hours    = [9,10,11,12,13,14,15,16,17,18,19];
  const dayNames = ['Mon','Tue','Wed','Thu','Fri','Sat'];
  let weekOffset = 0;

  const fmtHour = h => h === 12 ? '12 PM' : h < 12 ? `${h} AM` : `${h-12} PM`;

  function getMonday(offset) {
    const d   = new Date();
    const day = d.getDay();
    d.setDate(d.getDate() + (day === 0 ? -6 : 1 - day) + offset * 7);
    d.setHours(0,0,0,0);
    return d;
  }

  function render() {
    grid.innerHTML = '';
    const monday = getMonday(weekOffset);
    const today  = new Date(); today.setHours(0,0,0,0);

    const opts = { month:'short', day:'numeric' };
    const sat  = new Date(monday); sat.setDate(monday.getDate() + 5);
    weekLabel.textContent = `${monday.toLocaleDateString('en-US', opts)} – ${sat.toLocaleDateString('en-US', {...opts, year:'numeric'})}`;

    // Header row
    const timeHead = Object.assign(document.createElement('div'), { className:'cal-header-cell time-header' });
    grid.appendChild(timeHead);

    for (let d = 0; d < 6; d++) {
      const day = new Date(monday); day.setDate(monday.getDate() + d);
      const isToday = day.getTime() === today.getTime();
      const cell = document.createElement('div');
      cell.className = 'cal-header-cell' + (isToday ? ' today' : '');
      cell.setAttribute('role', 'columnheader');
      cell.innerHTML = `<div class="cal-day-name">${dayNames[d]}</div><div class="cal-day-date">${day.getDate()}</div>`;
      grid.appendChild(cell);
    }

    // Time slot rows
    const nowH = new Date().getHours();
    hours.forEach(h => {
      const timeCell = document.createElement('div');
      timeCell.className = 'cal-time-label cal-time-col';
      timeCell.textContent = fmtHour(h);
      grid.appendChild(timeCell);

      for (let d = 0; d < 6; d++) {
        const day = new Date(monday); day.setDate(monday.getDate() + d);
        const isPast = day < today || (day.getTime() === today.getTime() && h < nowH);
        let status = (schedule[d]?.[h]) || 'unavailable';
        if (isPast) status = 'unavailable';

        const slot = document.createElement('div');
        slot.className = `cal-slot ${status}`;
        slot.setAttribute('role', 'gridcell');
        slot.setAttribute('aria-label', `${dayNames[d]} ${fmtHour(h)} — ${status}`);

        if (status === 'available') {
          slot.textContent = 'Open';
          slot.tabIndex = 0;
          const select = () => {
            const tf = $('#preferred_time');
            if (tf) {
              tf.value = `${dayNames[d]} at ${fmtHour(h)}`;
              tf.scrollIntoView({ behavior:'smooth', block:'center' });
              tf.focus();
            }
          };
          slot.addEventListener('click', select);
          slot.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') select(); });
        } else if (status === 'booked') {
          slot.textContent = 'Booked';
        }

        grid.appendChild(slot);
      }
    });
  }

  prevBtn.addEventListener('click', () => { weekOffset--; render(); });
  nextBtn.addEventListener('click', () => { weekOffset++; render(); });
  render();
})();

/* ─────────────────────────────────────── CONTACT FORM ── */
(function initContactForm() {
  const form      = $('#contact-form');
  if (!form) return;
  const statusEl  = $('#form-status');
  const submitBtn = $('#submit-btn');

  function setError(input, msg) {
    input.classList.add('error');
    const err = input.parentElement.querySelector('.form-error');
    if (err) err.textContent = msg;
  }
  function clearError(input) {
    input.classList.remove('error');
    const err = input.parentElement.querySelector('.form-error');
    if (err) err.textContent = '';
  }

  function validateField(el) {
    if (el.name === 'website') return true;
    if (el.required && !el.value.trim()) { setError(el, 'This field is required.'); return false; }
    if (el.type === 'email' && el.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(el.value.trim())) {
      setError(el, 'Please enter a valid email address.'); return false;
    }
    clearError(el); return true;
  }

  $$('input, select, textarea', form).forEach(el => {
    el.addEventListener('input',  () => { if (el.value.trim()) clearError(el); });
    el.addEventListener('blur',   () => validateField(el));
  });

  form.addEventListener('submit', async e => {
    e.preventDefault();
    if ($('[name="website"]', form)?.value) return; // honeypot

    const valid = $$('[required]', form).map(validateField).every(Boolean);
    if (!valid) { setStatus('error-msg', 'Please correct the errors above.'); return; }

    submitBtn.disabled = true;
    submitBtn.classList.add('loading');
    statusEl.className = 'form-status';
    statusEl.textContent = '';

    try {
      const res  = await fetch('php/contact.php', { method:'POST', body: new FormData(form) });
      const json = await res.json();

      if (json.success) {
        setStatus('success', json.message || "Thank you! I'll be in touch soon.");
        form.reset();
        flashFormBorder();
      } else {
        setStatus('error-msg', (json.errors ?? [json.message]).filter(Boolean).join(', ') || 'Something went wrong.');
      }
    } catch (err) {
      // If PHP is not available (static file preview), show a friendly note
      setStatus('success', "Thank you! Your message has been received. (PHP mailer required for delivery.)");
      form.reset();
    } finally {
      submitBtn.disabled = false;
      submitBtn.classList.remove('loading');
    }
  });

  function setStatus(type, msg) {
    statusEl.className = 'form-status ' + type;
    statusEl.innerHTML = (type === 'success' ? '&#10003; ' : '&#10007; ') + msg;
  }

  function flashFormBorder() {
    const wrap = $('.contact-form-wrap');
    if (!wrap) return;
    wrap.style.transition = 'border-color 0.4s ease, box-shadow 0.4s ease';
    wrap.style.borderColor = 'var(--gold)';
    wrap.style.boxShadow   = '0 0 40px rgba(201,168,76,0.15)';
    setTimeout(() => { wrap.style.borderColor = ''; wrap.style.boxShadow = ''; }, 2200);
  }
})();

/* ──────────────────────────────── ANIMATED WAVEFORM ── */
(function initWaveform() {
  const poly = $('#waveform-line');
  if (!poly) return;
  const W = 300, H = 60, cy = H / 2;
  let phase = 0;

  function buildPoints() {
    const pts = [];
    for (let i = 0; i <= 80; i++) {
      const x  = (i / 80) * W;
      const nx = i / 80;
      const y  = cy
        + Math.sin(nx * Math.PI * 6  + phase)       * 8  * Math.sin(nx * Math.PI)
        + Math.sin(nx * Math.PI * 12 + phase * 2)   * 4  * Math.sin(nx * Math.PI)
        + Math.sin(nx * Math.PI * 3  + phase * 0.7) * 6;
      pts.push(`${x.toFixed(1)},${y.toFixed(1)}`);
    }
    return pts.join(' ');
  }

  (function animate() { phase += 0.025; poly.setAttribute('points', buildPoints()); requestAnimationFrame(animate); })();
})();

/* ─────────────────────────────────────── PRICING REVEAL ── */
(function initPricingReveal() {
  const cards = $$('.pricing-card');
  const obs = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (!e.isIntersecting) return;
      const i = cards.indexOf(e.target);
      setTimeout(() => {
        e.target.style.opacity   = '1';
        e.target.style.transform = 'translateY(0)';
      }, i * 100);
      obs.unobserve(e.target);
    });
  }, { threshold: 0.15 });

  cards.forEach(c => {
    c.style.opacity   = '0';
    c.style.transform = 'translateY(20px)';
    c.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
    obs.observe(c);
  });
})();

/* ────────────────────────────────────── PORTRAIT PARALLAX ── */
(function initParallax() {
  const portrait = $('.about-portrait');
  if (!portrait || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  window.addEventListener('scroll', () => {
    const rect = portrait.getBoundingClientRect();
    const offset = ((rect.top + rect.height / 2) - window.innerHeight / 2) * 0.05;
    portrait.style.transform = `translateY(${clamp(offset, -18, 18)}px)`;
  }, { passive: true });
})();

/* ──────────────────────────────────────────── FOOTER ── */
(function initFooter() {
  const y = $('#footer-year');
  if (y) y.textContent = new Date().getFullYear();
})();

/* ─────────────────────────────────── SMOOTH SCROLL ── */
document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', e => {
    const target = document.querySelector(a.getAttribute('href'));
    if (target) { e.preventDefault(); target.scrollIntoView({ behavior:'smooth', block:'start' }); }
  });
});
