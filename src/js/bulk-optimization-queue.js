/*
 * Bulk Optimization Queue
 *
 * Starting sends one request that hands the library to the background queue;
 * from then on this script only polls for progress. What each state looks like
 * is decided server side and arrives as `display`, the same thing PHP renders
 * the page with, so the first paint is already right and this only has to keep
 * it up to date. Nothing is kept on this side, so a reload, a cancelled run or
 * a process that died halfway all render the same.
 */
(() => {
  const POLL_INTERVAL = 1000;

  /* Paused, nothing changes until the image already under way finishes. */
  const PAUSED_POLL_INTERVAL = 5000;

  let pollTimer = null;
  let pausing = false;
  let el = null;

  async function request(action) {
    try {
      const response = await fetch(ajaxurl, {
        method: 'POST',
        credentials: 'same-origin',
        body: new URLSearchParams({ action, _nonce: tinyCompress.nonce }),
      });
      return response.ok ? await response.json() : null;
    } catch {
      return null;
    }
  }

  function format(template, value) {
    return String(template).replace('%s', value);
  }

  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text !== undefined) {
      node.textContent = text;
    }
    return node;
  }

  /* Header ---------------------------------------------------------------- */

  function renderHeader(display) {
    el.statusIcon.replaceChildren();

    if (display.spinner) {
      el.statusIcon.append(element('span', 'tiny-queue-spinner'));
    } else if (display.icon) {
      el.statusIcon.append(element('span', `dashicons ${display.icon}`));
    }

    el.statusLabel.textContent = display.label;
    el.status.hidden = display.label === '' && !display.icon && !display.spinner;

    el.subtitle.textContent = display.subtitle;
    el.subtitle.hidden = display.subtitle === '';
  }

  /* The line under the bar: what is happening, or why nothing is. */
  function renderDetails(details) {
    el.details.classList.toggle('is-error', details.error);

    if (details.text === '') {
      el.details.hidden = true;
      return;
    }

    el.details.replaceChildren(document.createTextNode(details.text));

    if (details.name !== '') {
      el.details.append(
        document.createTextNode(' '),
        element('strong', null, details.name)
      );
    }

    el.details.hidden = false;
  }

  /* Rows ------------------------------------------------------------------ */

  function rowTitle(item) {
    const title = item.title || String(item.id);
    return item.id ? `#${item.id} ${title}` : title;
  }

  /*
   * Waiting and under way only mean anything while there is a queue, paused or
   * not. Without one a row falls back to what the image itself is: an upload
   * that was never compressed has nothing to report, and one compressed in an
   * earlier run still shows what it saved.
   */
  function isLive(item) {
    return item.status === 'pending' || item.status === 'processing';
  }

  function hasResult(item) {
    return (
      (parseInt(item.sizes_compressed, 10) || 0) +
        (parseInt(item.sizes_converted, 10) || 0) >
      0
    );
  }

  function statusIcon(item, running) {
    if (running && isLive(item)) {
      return element(
        'span',
        item.status === 'processing' ? 'tiny-queue-spinner' : 'tiny-queue-dot'
      );
    }
    if (item.status === 'failed') {
      return element('span', 'dashicons dashicons-warning');
    }
    if (hasResult(item)) {
      return element('span', 'dashicons dashicons-yes');
    }
    if (item.status === 'skipped') {
      return element('span', 'dashicons dashicons-minus');
    }
    return null;
  }

  function savedLine(item) {
    const line = element('div', 'tiny-queue-result-primary');
    line.append(document.createTextNode(`${item.optimized_size} `));
    line.append(element('span', null, format(tinyCompress.L10nQueueSaved, item.savings)));
    return line;
  }

  /* What an image ended up with, or what it is still waiting for. */
  function rowResult(item, running) {
    const result = element('div', 'tiny-queue-row-result');

    if (running && item.status === 'processing') {
      const percentage = parseInt(item.progress, 10) || 0;
      result.classList.add('is-active');
      result.append(
        element(
          'div',
          'tiny-queue-result-primary',
          `${format(tinyCompress.L10nQueueSizeProgress, percentage)}…`
        )
      );

      const track = element('div', 'tiny-queue-mini-track');
      const fill = element('div', 'tiny-queue-mini-fill');
      fill.style.width = `${percentage}%`;
      track.append(fill);
      result.append(track);
      return result;
    }

    if (running && item.status === 'pending') {
      result.classList.add('is-waiting');
      result.append(element('div', 'tiny-queue-result-primary', tinyCompress.L10nQueueQueued));
      result.append(
        element('div', 'tiny-queue-result-secondary', `${tinyCompress.L10nWaiting}…`)
      );
      return result;
    }

    if (item.status === 'failed') {
      result.classList.add('is-failed');
      result.append(element('div', 'tiny-queue-result-primary', tinyCompress.L10nError));
      if (item.message) {
        result.append(element('div', 'tiny-queue-result-secondary', item.message));
      }
      return result;
    }

    if (hasResult(item)) {
      result.classList.add('is-saved');
      result.append(savedLine(item));

      const converted = parseInt(item.sizes_converted, 10) || 0;
      const compressed = parseInt(item.sizes_compressed, 10) || 0;

      result.append(
        element(
          'div',
          'tiny-queue-result-secondary',
          converted > 0
            ? `${converted} ${tinyCompress.L10nConverted}`
            : `${compressed} ${tinyCompress.L10nCompressed}`
        )
      );

      return result;
    }

    if (item.status === 'skipped') {
      result.classList.add('is-skipped');
      result.append(
        element('div', 'tiny-queue-result-primary', tinyCompress.L10nNoActionTaken)
      );
    }

    return result;
  }

  function buildRow(item, running) {
    const row = element('div', 'tiny-queue-row');
    if (item.status === 'processing' && running) {
      row.classList.add('is-processing');
    }

    const icon = element('div', 'tiny-queue-row-icon');
    const marker = statusIcon(item, running);
    if (marker) {
      icon.append(marker);
    }

    const thumbnail = element('div', 'tiny-queue-thumbnail');
    /* Markup built by wp_get_attachment_image(). */
    thumbnail.innerHTML = item.thumbnail || '';

    const meta = element('div', 'tiny-queue-row-meta');
    meta.append(element('div', 'tiny-queue-row-title', rowTitle(item)));
    if (item.filename) {
      meta.append(element('div', 'tiny-queue-row-file', item.filename));
    }

    const left = element('div', 'tiny-queue-row-left');
    left.append(icon, thumbnail, meta);

    const initial = element('div', 'tiny-queue-row-initial');
    initial.append(element('b', null, item.initial_size || '-'));
    if (item.sizes > 0) {
      initial.append(element('span', null, format(tinyCompress.L10nQueueSizes, item.sizes)));
    }

    const right = element('div', 'tiny-queue-row-right');
    right.append(initial, rowResult(item, running));

    row.append(left, right);
    return row;
  }

  /*
   * The image being optimized, then the ones lined up behind it, in the order
   * the queue works through the library. An image leaves the list once the
   * queue is done with it.
   */
  function renderRows(state, queued) {
    const seen = new Set();
    const items = [];

    for (const item of [...(state.current || []), ...(state.queued || [])]) {
      if (!seen.has(item.id)) {
        seen.add(item.id);
        items.push(item);
      }
    }

    el.rows.replaceChildren(...items.map((item) => buildRow(item, queued)));
  }

  /* Buttons --------------------------------------------------------------- */

  function renderActions(display) {
    const actions = display.actions;

    if (!display.running) {
      pausing = false;
    }

    /* The labels are server rendered and never change, so the icon survives. */
    el.start.hidden = !actions.start;
    el.start.disabled = false;

    /*
     * Pausing only puts up a flag; the process holding the queue reads it
     * between images and can take a moment to get there. Leave the button off
     * until it does rather than invite a second click that changes nothing.
     */
    el.pause.hidden = !actions.pause;
    el.pause.disabled = pausing;

    el.resume.hidden = !actions.resume;
    el.resume.disabled = false;

    el.cancel.hidden = !actions.cancel;
    el.cancel.disabled = false;

    el.runSpinner.hidden = !display.running;
  }

  /* ----------------------------------------------------------------------- */

  function render(state) {
    // A permission failure comes back as a bare {error: ...} with no display.
    if (!state || !state.display) {
      return;
    }

    const display = state.display;

    /* An empty library has nothing to measure and nothing to list. */
    el.card.classList.toggle('is-empty', display.empty);
    el.progress.hidden = display.empty;
    el.list.hidden = !display.list;

    el.fill.style.width = `${display.percentage}%`;
    el.fill.classList.toggle('is-complete', display.complete);
    el.percentage.classList.toggle('is-complete', display.complete);
    el.percentage.textContent = display.left;
    el.remaining.textContent = display.right;

    renderHeader(display);
    renderDetails(display.details);
    renderRows(state, display.running || display.paused);
    renderActions(display);

    if (display.running) {
      schedulePoll(POLL_INTERVAL);
    } else if (display.paused && state.is_processing) {
      /* Paused, but an image was already under way and has to finish. */
      schedulePoll(PAUSED_POLL_INTERVAL);
    } else {
      stopPolling();
    }
  }

  function schedulePoll(delay) {
    if (pollTimer) {
      return;
    }
    pollTimer = setTimeout(async () => {
      pollTimer = null;
      const state = await request('tiny_bulk_queue_status');
      if (state) {
        render(state);
      } else {
        schedulePoll(delay);
      }
    }, delay);
  }

  function stopPolling() {
    clearTimeout(pollTimer);
    pollTimer = null;
  }

  async function start() {
    el.start.disabled = true;
    pausing = false;
    render(await request('tiny_bulk_queue_start'));
  }

  async function pause() {
    el.pause.disabled = true;
    pausing = true;
    render(await request('tiny_bulk_queue_pause'));
  }

  async function resume() {
    el.resume.disabled = true;
    pausing = false;
    render(await request('tiny_bulk_queue_resume'));
  }

  async function cancel() {
    el.cancel.disabled = true;
    render(await request('tiny_bulk_queue_cancel'));
  }

  window.tinyBulkQueue = (state) => {
    el = {
      start: document.getElementById('tiny-queue-start'),
      pause: document.getElementById('tiny-queue-pause'),
      resume: document.getElementById('tiny-queue-resume'),
      cancel: document.getElementById('tiny-queue-cancel'),
      runSpinner: document.getElementById('tiny-queue-run-spinner'),
      status: document.getElementById('tiny-queue-status'),
      statusIcon: document.getElementById('tiny-queue-status-icon'),
      statusLabel: document.getElementById('tiny-queue-status-label'),
      subtitle: document.getElementById('tiny-queue-subtitle'),
      fill: document.getElementById('tiny-queue-fill'),
      percentage: document.getElementById('tiny-queue-percentage'),
      remaining: document.getElementById('tiny-queue-remaining'),
      details: document.getElementById('tiny-queue-details'),
      card: document.getElementById('tiny-queue-card'),
      progress: document.getElementById('tiny-queue-progress'),
      list: document.getElementById('tiny-queue-list'),
      rows: document.getElementById('tiny-queue-rows'),
    };

    el.start.addEventListener('click', start);
    el.pause.addEventListener('click', pause);
    el.resume.addEventListener('click', resume);
    el.cancel.addEventListener('click', cancel);
    render(state);
  };
})();
