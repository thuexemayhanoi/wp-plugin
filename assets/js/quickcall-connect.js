/*
 * QuickCall Connect - frontend script (vanilla JS, no dependencies).
 *
 * Progressive enhancement: the markup shows all links without JS.
 * This script only adds the collapsible launcher behavior for floating modes.
 */
(function () {
	'use strict';

	function init() {
		var root = document.querySelector('.qc-root');
		if (!root || root.classList.contains('qc-dock')) {
			return; // Bottom dock needs no JS.
		}

		var toggle = root.querySelector('.qc-toggle');
		if (!toggle) {
			return;
		}

		// From here on, JS is active: collapse actions until opened.
		root.classList.add('qc-js');

		function isOpen() {
			return root.classList.contains('qc-open');
		}

		function open() {
			root.classList.add('qc-open');
			toggle.setAttribute('aria-expanded', 'true');
			toggle.setAttribute('aria-label', toggle.getAttribute('data-label-close') || 'Close quick contact options');
		}

		function close(focusToggle) {
			root.classList.remove('qc-open');
			toggle.setAttribute('aria-expanded', 'false');
			toggle.setAttribute('aria-label', toggle.getAttribute('data-label-open') || 'Open quick contact options');
			if (focusToggle) {
				toggle.focus();
			}
		}

		toggle.addEventListener('click', function () {
			if (isOpen()) {
				close(false);
			} else {
				open();
			}
		});

		// Escape closes and returns focus to the launcher.
		root.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && isOpen()) {
				close(true);
			}
		});

		// Click/tap outside closes.
		document.addEventListener('click', function (e) {
			if (isOpen() && !root.contains(e.target)) {
				close(false);
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
