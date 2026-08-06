/*
 * Tiny Compress Images - the front-end admin bar Images panel.
 *
 * Collects image references from the rendered page when the panel is opened, posts them to
 * be scanned, and drives Optimize. Nothing here runs on page load beyond binding one
 * listener: the scan is the cost of opening the panel, not of viewing the page.
 */
(function () {
  'use strict';

  var panel = null;
  var scanned = false;
  var cancelled = false;
  var running = false;

  /* ------------------------------------------------------------ collecting the page */

  function absoluteURL(url) {
    try {
      return new URL(url, window.location.href).href;
    } catch (e) {
      return '';
    }
  }

  function urlsFromSrcset(srcset) {
    if (!srcset) {
      return [];
    }
    return srcset
      .split(',')
      .map(function (candidate) {
        return candidate.trim().split(/\s+/)[0];
      })
      .filter(Boolean);
  }

  /*
   * One reference per <img>. A <picture> folds its <source> urls into the reference of the
   * <img> it wraps, so a converted AVIF or WebP url arrives alongside the size it was
   * converted from rather than as a thing of its own.
   */
  function collectReferences() {
    var references = [];
    var images = document.querySelectorAll('img');
    var index;

    for (index = 0; index < images.length; index++) {
      var image = images[index];

      // The admin bar's own avatars are chrome, not page content.
      if (image.closest('#wpadminbar')) {
        continue;
      }

      var urls = [];
      var src = image.getAttribute('src');

      if (src) {
        urls.push(src);
      }
      urls = urls.concat(urlsFromSrcset(image.getAttribute('srcset')));

      var picture = image.closest('picture');
      if (picture) {
        var sources = picture.querySelectorAll('source');
        var sourceIndex;

        for (sourceIndex = 0; sourceIndex < sources.length; sourceIndex++) {
          var source = sources[sourceIndex];
          urls = urls.concat(urlsFromSrcset(source.getAttribute('srcset')));

          var sourceSrc = source.getAttribute('src');
          if (sourceSrc) {
            urls.push(sourceSrc);
          }
        }
      }

      var unique = [];
      urls.forEach(function (url) {
        var resolved = absoluteURL(url);
        if (resolved && unique.indexOf(resolved) === -1) {
          unique.push(resolved);
        }
      });

      if (unique.length) {
        references.push({ src: absoluteURL(src) || unique[0], urls: unique });
      }
    }

    return references;
  }

  /* --------------------------------------------------------------------- transport */

  function post(action, fields) {
    var body = new URLSearchParams();

    body.set('action', action);
    body.set('_nonce', window.tinyImages.nonce);
    Object.keys(fields).forEach(function (key) {
      body.set(key, fields[key]);
    });

    return fetch(window.tinyImages.ajaxurl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function (response) {
      if (!response.ok) {
        throw new Error(response.status);
      }
      return response.text();
    });
  }

  function fragments(html) {
    var holder = document.createElement('div');
    holder.innerHTML = html.trim();
    return holder;
  }

  function message(text) {
    panel.innerHTML = '';
    var paragraph = document.createElement('p');
    paragraph.className = 'tiny-images-message';
    paragraph.textContent = text;
    panel.appendChild(paragraph);
  }

  /* -------------------------------------------------------------------- the scan */

  function runScan() {
    panel.setAttribute('data-state', 'loading');
    message(window.tinyImages.L10nScanning + '…');

    post('tiny_scan_page', { references: JSON.stringify(collectReferences()) })
      .then(function (html) {
        panel.innerHTML = html;
        panel.setAttribute('data-state', 'ready');
      })
      .catch(function () {
        panel.setAttribute('data-state', 'error');
        message(window.tinyImages.L10nRequestError);
      });
  }

  /* ----------------------------------------------------------------- optimizing */

  function currentSummary() {
    var header = panel.querySelector('[data-tiny-fragment="header"]');
    return header ? header.getAttribute('data-summary') : '{}';
  }

  function replaceFragments(holder, row) {
    var newHeader = holder.querySelector('[data-tiny-fragment="header"]');
    var newRow = holder.querySelector('[data-tiny-fragment="row"]');
    var oldHeader = panel.querySelector('[data-tiny-fragment="header"]');

    if (newHeader && oldHeader) {
      oldHeader.parentNode.replaceChild(newHeader, oldHeader);
    }
    if (newRow && row) {
      row.parentNode.replaceChild(newRow, row);
    }
  }

  /* Resolves to true when the account is blocked and no further row can succeed. */
  function optimizeRow(row) {
    var button = row.querySelector('.tiny-images-optimize');

    row.classList.add('is-working');
    if (button) {
      button.disabled = true;
      button.textContent = window.tinyImages.L10nOptimizing + '…';
    }

    return post('tiny_optimize_page_image', {
      id: row.getAttribute('data-id'),
      sizes: row.getAttribute('data-sizes'),
      summary: currentSummary()
    })
      .then(function (html) {
        var holder = fragments(html);
        replaceFragments(holder, row);
        return !!holder.querySelector('.tiny-images-account');
      })
      .catch(function () {
        row.classList.remove('is-working');
        if (button) {
          button.disabled = false;
          button.textContent = window.tinyImages.L10nRequestError;
        }
        return false;
      });
  }

  /*
   * Serial, one row at a time. Firing these in parallel would put one admin-ajax request
   * per image in flight from the front end of a live site, and each of those is several
   * calls to the compression API.
   */
  function optimizeAll() {
    var queue = Array.prototype.slice.call(
      panel.querySelectorAll('.tiny-images-row[data-state="optimizable"]')
    );

    cancelled = false;
    running = true;
    renderCancel();

    function next() {
      if (cancelled || !queue.length) {
        running = false;
        renderCancel();
        return Promise.resolve();
      }

      return optimizeRow(queue.shift()).then(function (accountBlocked) {
        // Nothing else can succeed until the account is dealt with, so stop asking.
        if (accountBlocked) {
          cancelled = true;
        }
        return next();
      });
    }

    return next();
  }

  function renderCancel() {
    var button = panel.querySelector('.tiny-images-optimize-all');
    if (!button) {
      return;
    }

    if (running) {
      button.classList.add('tiny-images-button--cancel');
      button.textContent = window.tinyImages.L10nCancel;
    } else {
      button.classList.remove('tiny-images-button--cancel');
    }
  }

  /* ----------------------------------------------------------------------- wiring */

  document.addEventListener('DOMContentLoaded', function () {
    var node = document.getElementById('wp-admin-bar-tiny-images');
    if (!node) {
      return;
    }

    panel = node.querySelector('.tiny-images-panel');
    if (!panel) {
      return;
    }

    function open() {
      if (!scanned) {
        scanned = true;
        runScan();
      }
    }

    node.addEventListener('mouseenter', open);
    node.addEventListener('click', open);
    node.addEventListener('focusin', open);

    node.addEventListener('click', function (event) {
      if (event.target.closest('.tiny-images-optimize')) {
        event.preventDefault();
        optimizeRow(event.target.closest('.tiny-images-row'));
        return;
      }

      if (event.target.closest('.tiny-images-optimize-all')) {
        event.preventDefault();
        if (running) {
          cancelled = true;
        } else {
          optimizeAll();
        }
      }
    });
  });
})();
