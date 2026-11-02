(() => {
  const deck = document.getElementById('deck');
  if (!deck) return;

  const slides = [...deck.querySelectorAll('.slide')];
  const nav = document.getElementById('slide-nav');
  const counter = document.getElementById('step-count');
  const hint = document.getElementById('scroll-hint');
  const finishPlatform = document.getElementById('finish-platform');
  const modeButtons = [...document.querySelectorAll('[data-mode-button]')];
  const paths = {
    mac: ['intro', 'platform', 'prepare', 'download-mac', 'configure-mac', 'dry-run-mac', 'first-mac', 'schedule-mac', 'finish'],
    windows: ['intro', 'platform', 'prepare', 'download-windows', 'configure-windows', 'dry-run-windows', 'first-windows', 'schedule-windows', 'finish']
  };

  let platform = '';
  let currentIndex = 0;
  let mode = 'guide';
  let wheelLock = false;
  let manualObserver = null;

  try {
    const saved = sessionStorage.getItem('files-sync-platform');
    if (saved === 'mac' || saved === 'windows') platform = saved;
  } catch (_) {}

  function path() {
    return platform ? paths[platform] : ['intro', 'platform'];
  }

  function updateCounter() {
    const route = path();
    if (mode === 'manual') {
      counter.textContent = platform ? 'Read all · ' + route.length + ' steps' : 'Choose a computer';
    } else if (platform) {
      counter.textContent = 'Step ' + String(currentIndex + 1).padStart(2, '0') + ' / ' + String(route.length).padStart(2, '0');
    } else {
      counter.textContent = currentIndex === 0 ? 'Start here' : 'Choose your computer';
    }
    if (finishPlatform) {
      finishPlatform.textContent = platform === 'mac' ? 'Mac sync guide complete' : platform === 'windows' ? 'Windows sync guide complete' : 'Sync guide complete';
    }
  }

  function renderDots() {
    nav.replaceChildren();
    const route = path();
    route.forEach((name, index) => {
      const slide = slides.find(item => item.dataset.screen === name);
      const dot = document.createElement('button');
      dot.type = 'button';
      dot.className = 'dot';
      dot.setAttribute('aria-label', 'Go to step ' + (index + 1) + ': ' + (slide?.dataset.label || name));
      dot.setAttribute('aria-current', index === currentIndex ? 'step' : 'false');
      dot.addEventListener('click', () => goTo(index));
      nav.append(dot);
    });
  }

  function renderSlides() {
    const route = path();
    const visible = new Set(route);
    slides.forEach(slide => {
      const show = visible.has(slide.dataset.screen);
      const current = show && route[currentIndex] === slide.dataset.screen;
      slide.hidden = !show || (mode === 'guide' && !current);
      slide.classList.toggle('active', mode === 'manual' ? show : current);
      slide.classList.toggle('is-current', current);
    });

    if (mode === 'manual') {
      nav.hidden = true;
      hint.hidden = true;
    } else {
      nav.hidden = false;
      hint.hidden = currentIndex > 0;
    }
    updateCounter();
    renderDots();
  }

  function goTo(index, shouldScroll = true) {
    const route = path();
    currentIndex = Math.max(0, Math.min(route.length - 1, index));
    renderSlides();
    if (shouldScroll) {
      const target = slides.find(item => item.dataset.screen === route[currentIndex]);
      target?.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
    }
  }

  function selectPlatform(value) {
    if (value !== 'mac' && value !== 'windows') return;
    if (manualObserver) manualObserver.disconnect();
    manualObserver = null;
    platform = value;
    deck.dataset.platform = value;
    try { sessionStorage.setItem('files-sync-platform', value); } catch (_) {}
    currentIndex = 2;
    mode = 'guide';
    deck.dataset.mode = mode;
    modeButtons.forEach(button => {
      const selected = button.dataset.modeButton === mode;
      button.classList.toggle('is-active', selected);
      button.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
    renderSlides();
    goTo(2);
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
      if (manualObserver) manualObserver.disconnect();
      manualObserver = new IntersectionObserver(entries => {
        const current = entries
          .filter(entry => entry.isIntersecting)
          .sort((left, right) => right.intersectionRatio - left.intersectionRatio)[0];
        if (!current) return;
        const index = path().indexOf(current.target.dataset.screen);
        if (index < 0) return;
        currentIndex = index;
        slides.forEach(slide => slide.classList.toggle('is-current', slide === current.target));
        updateCounter();
      }, { threshold: [0.25, 0.45, 0.65] });
      path().forEach(name => {
        const slide = slides.find(item => item.dataset.screen === name);
        if (slide) manualObserver.observe(slide);
      });
    } else {
      if (manualObserver) manualObserver.disconnect();
      manualObserver = null;
      goTo(currentIndex);
    }
  }

  document.documentElement.classList.remove('no-js');
  document.documentElement.classList.add('js');
  deck.dataset.platform = platform;
  renderSlides();

  document.addEventListener('click', event => {
    const platformButton = event.target.closest('[data-platform-choice]');
    if (platformButton) {
      selectPlatform(platformButton.dataset.platformChoice);
      return;
    }

    const modeButton = event.target.closest('[data-mode-button]');
    if (modeButton) {
      setMode(modeButton.dataset.modeButton);
      return;
    }

    const nextButton = event.target.closest('[data-next]');
    if (nextButton) {
      goTo(currentIndex + 1);
      return;
    }

    const previousButton = event.target.closest('[data-previous]');
    if (previousButton) {
      goTo(currentIndex - 1);
      return;
    }

    const goButton = event.target.closest('[data-go]');
    if (goButton) {
      const index = path().indexOf(goButton.dataset.go);
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
      if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(value).then(done).catch(() => fallbackCopy(value, done));
      } else {
        fallbackCopy(value, done);
      }
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
    try {
      if (document.execCommand('copy')) done();
    } finally {
      textarea.remove();
    }
  }

  window.addEventListener('keydown', event => {
    if (mode !== 'guide' || event.altKey || event.ctrlKey || event.metaKey) return;
    const tag = event.target?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || tag === 'BUTTON' || tag === 'A') return;
    if (!['ArrowDown', 'ArrowRight', 'PageDown', 'ArrowUp', 'ArrowLeft', 'PageUp', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    if (event.key === 'Home') goTo(0);
    else if (event.key === 'End') goTo(path().length - 1);
    else if (['ArrowDown', 'ArrowRight', 'PageDown'].includes(event.key)) goTo(currentIndex + 1);
    else goTo(currentIndex - 1);
  });

  window.addEventListener('wheel', event => {
    if (mode !== 'guide' || window.innerWidth <= 820 || Math.abs(event.deltaY) < 26 || wheelLock) return;
    const currentSlide = slides.find(item => item.dataset.screen === path()[currentIndex]);
    if (!currentSlide) return;
    const bounds = currentSlide.getBoundingClientRect();
    if (event.deltaY > 0 && bounds.bottom > window.innerHeight + 10) return;
    if (event.deltaY < 0 && bounds.top < -10) return;
    wheelLock = true;
    goTo(currentIndex + (event.deltaY > 0 ? 1 : -1));
    window.setTimeout(() => { wheelLock = false; }, 650);
  }, { passive: true });
})();

