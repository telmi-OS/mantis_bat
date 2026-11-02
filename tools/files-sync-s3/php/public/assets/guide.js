(() => {
  const deck = document.getElementById('deck');
  if (!deck) return;

  const slides = [...deck.querySelectorAll('.slide')];
  const route = slides.map(slide => slide.dataset.screen);
  const nav = document.getElementById('slide-nav');
  const counter = document.getElementById('step-count');
  const hint = document.getElementById('scroll-hint');
  const modeButtons = [...document.querySelectorAll('[data-mode-button]')];
  let currentIndex = 0;
  let mode = 'guide';
  let wheelLock = false;
  let manualObserver = null;

  function updateCounter() {
    counter.textContent = mode === 'manual'
      ? 'Read all · ' + route.length + ' steps'
      : 'Step ' + String(currentIndex + 1).padStart(2, '0') + ' / ' + String(route.length).padStart(2, '0');
  }

  function renderDots() {
    nav.replaceChildren();
    route.forEach((name, index) => {
      const slide = slides[index];
      const dot = document.createElement('button');
      dot.type = 'button';
      dot.className = 'dot';
      dot.setAttribute('aria-label', 'Go to step ' + (index + 1) + ': ' + (slide.dataset.label || name));
      dot.setAttribute('aria-current', index === currentIndex ? 'step' : 'false');
      dot.addEventListener('click', () => goTo(index));
      nav.append(dot);
    });
  }

  function renderSlides() {
    slides.forEach((slide, index) => {
      const current = index === currentIndex;
      slide.hidden = mode === 'guide' && !current;
      slide.classList.toggle('active', mode === 'manual' || current);
      slide.classList.toggle('is-current', current);
    });
    nav.hidden = mode === 'manual';
    hint.hidden = mode === 'manual' || currentIndex === 0;
    updateCounter();
    renderDots();
  }

  function goTo(index, shouldScroll = true) {
    currentIndex = Math.max(0, Math.min(route.length - 1, index));
    renderSlides();
    if (shouldScroll) {
      const target = slides[currentIndex];
      target?.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
    }
  }

  function setMode(value) {
    if (value !== 'guide' && value !== 'manual') return;
    mode = value;
    deck.dataset.mode = mode;
    modeButtons.forEach(button => {
      const selected = button.dataset.modeButton === mode;
      button.classList.toggle('is-active', selected);
      button.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
    renderSlides();
    if (mode === 'manual') {
      if (!('IntersectionObserver' in window)) return;
      if (manualObserver) manualObserver.disconnect();
      manualObserver = new IntersectionObserver(entries => {
        const current = entries.filter(entry => entry.isIntersecting)
          .sort((left, right) => right.intersectionRatio - left.intersectionRatio)[0];
        if (!current) return;
        const index = slides.indexOf(current.target);
        if (index < 0) return;
        currentIndex = index;
        slides.forEach((slide, slideIndex) => slide.classList.toggle('is-current', slideIndex === index));
        updateCounter();
        renderDots();
      }, { threshold: [0.25, 0.45, 0.65] });
      slides.forEach(slide => manualObserver.observe(slide));
    } else {
      if (manualObserver) manualObserver.disconnect();
      manualObserver = null;
      goTo(currentIndex);
    }
  }

  document.documentElement.classList.remove('no-js');
  document.documentElement.classList.add('js');
  deck.dataset.mode = mode;
  renderSlides();

  document.addEventListener('click', event => {
    const modeButton = event.target.closest('[data-mode-button]');
    if (modeButton) {
      setMode(modeButton.dataset.modeButton);
      return;
    }
    if (event.target.closest('[data-next]')) {
      goTo(currentIndex + 1);
      return;
    }
    if (event.target.closest('[data-previous]')) {
      goTo(currentIndex - 1);
      return;
    }
    const goButton = event.target.closest('[data-go]');
    if (goButton) {
      const index = route.indexOf(goButton.dataset.go);
      if (index >= 0) goTo(index);
      return;
    }
    const copyButton = event.target.closest('[data-copy]');
    if (copyButton) {
      const block = document.getElementById(copyButton.dataset.copy);
      const value = block?.innerText || '';
      if (!value) return;
      const done = () => {
        const oldLabel = copyButton.textContent;
        copyButton.textContent = 'Copied';
        copyButton.classList.add('copied');
        window.setTimeout(() => {
          copyButton.textContent = oldLabel || 'Copy';
          copyButton.classList.remove('copied');
        }, 1400);
      };
      if (navigator.clipboard?.writeText) navigator.clipboard.writeText(value).then(done).catch(() => fallbackCopy(value, done));
      else fallbackCopy(value, done);
    }
  });

  function fallbackCopy(value, done) {
    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.append(textarea);
    textarea.select();
    try { if (document.execCommand('copy')) done(); } finally { textarea.remove(); }
  }

  window.addEventListener('keydown', event => {
    if (mode !== 'guide' || event.altKey || event.ctrlKey || event.metaKey) return;
    const tag = event.target?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || tag === 'BUTTON' || tag === 'A') return;
    if (!['ArrowDown', 'ArrowRight', 'PageDown', 'ArrowUp', 'ArrowLeft', 'PageUp', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    if (event.key === 'Home') goTo(0);
    else if (event.key === 'End') goTo(route.length - 1);
    else if (['ArrowDown', 'ArrowRight', 'PageDown'].includes(event.key)) goTo(currentIndex + 1);
    else goTo(currentIndex - 1);
  });

  window.addEventListener('wheel', event => {
    if (mode !== 'guide' || window.innerWidth <= 820 || Math.abs(event.deltaY) < 26 || wheelLock) return;
    const bounds = slides[currentIndex].getBoundingClientRect();
    if (event.deltaY > 0 && bounds.bottom > window.innerHeight + 10) return;
    if (event.deltaY < 0 && bounds.top < -10) return;
    wheelLock = true;
    goTo(currentIndex + (event.deltaY > 0 ? 1 : -1));
    window.setTimeout(() => { wheelLock = false; }, 650);
  }, { passive: true });
})();
