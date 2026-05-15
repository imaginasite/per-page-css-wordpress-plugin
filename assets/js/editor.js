(function (wp) {
	const i18n = window.imaginasitePerPageCssData.i18n;
	window.mgCodeEditorSettings = window.imaginasitePerPageCssData.settings;

	const el = wp.element.createElement;
	const PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
	const registerPlugin = wp.plugins.registerPlugin;
	const withSelect = wp.data.withSelect;
	const withDispatch = wp.data.withDispatch;
	const compose = wp.compose.compose;
	const useRef = wp.element.useRef;
	const useEffect = wp.element.useEffect;
	const useState = wp.element.useState;
	const useCallback = wp.element.useCallback;

	const STYLE_ID = 'mg-live-css';
	const EDITOR_STYLE_ID = 'mg-editor-style';
	const LOCK_NAME = 'imaginasite-per-page-css-invalid-css';
	const NOTICE_ID = 'imaginasite-css-error';

	let lastIframe = null;
	let lastInjectedCSS = null;
	let lastInjectedTarget = null;
	let debounceTimer = null;
	let observerScheduled = false;
	let lastValidationInvalid = null;

	if (!document.getElementById(EDITOR_STYLE_ID)) {
		const style = document.createElement('style');
		style.id = EDITOR_STYLE_ID;
		style.innerHTML = `
			.mg-code-editor-wrapper .CodeMirror {
				height: auto;
				min-height: 250px;
				border: 1px solid #ccc;
				border-radius: 2px;
				font-family: monospace;
			}
			.mg-code-editor-wrapper .CodeMirror-scroll {
				min-height: 250px;
			}
		`;
		document.head.appendChild(style);
	}

	function getMeta() {
		return wp.data.select('core/editor').getEditedPostAttribute('meta') || {};
	}

	function getCurrentCSS() {
		const meta = getMeta();
		return meta._imaginasite_per_page_css || '';
	}

	function getEditorCanvasIframe() {
		return (
			document.querySelector('iframe[name="editor-canvas"]') ||
			document.querySelector('.block-editor-iframe__container iframe') ||
			document.querySelector('iframe.editor-canvas__iframe') ||
			document.querySelector('iframe[srcdoc]')
		);
	}

	function injectIntoDoc(doc, css) {
		if (!doc || !doc.head) {
			return;
		}

		let styleEl = doc.getElementById(STYLE_ID);

		if (styleEl) {
			styleEl.remove();
		}

		styleEl = doc.createElement('style');
		styleEl.id = STYLE_ID;
		styleEl.appendChild(doc.createTextNode(css || ''));
		doc.head.appendChild(styleEl);
	}

	function adaptSelector(selector) {
		const cleaned = selector.trim();

		if (!cleaned) {
			return [];
		}

		if (cleaned.includes('@') || cleaned.includes(':root')) {
			return [cleaned];
		}

		if (cleaned === 'body' || cleaned === 'html') {
			return ['.editor-styles-wrapper'];
		}

		const variants = new Set();

		variants.add('.editor-styles-wrapper ' + cleaned);
		variants.add('.is-root-container ' + cleaned);

		const normalized = cleaned
			.replace(/\.entry-content\s*/g, '')
			.replace(/\.post-content\s*/g, '')
			.replace(/\.wp-site-blocks\s*/g, '')
			.replace(/\.site-main\s*/g, '')
			.trim();

		if (normalized && normalized !== cleaned) {
			variants.add('.editor-styles-wrapper ' + normalized);
		}

		return Array.from(variants);
	}

	function buildEditorPreviewCSS(css) {
		if (!css) {
			return '';
		}

		const cleanCSS = css.replace(/\/\*[\s\S]*?\*\//g, '');

		return cleanCSS.replace(/([^{}]+)\{([^{}]*)\}/g, function (match, selectorGroup, declarations) {
			const rawSelector = selectorGroup.trim();

			if (!rawSelector || rawSelector.startsWith('@')) {
				return match;
			}

			const selectors = rawSelector
				.split(',')
				.map(function (s) {
					return s.trim();
				})
				.filter(Boolean);

			const adapted = [];

			selectors.forEach(function (selector) {
				adaptSelector(selector).forEach(function (item) {
					if (!adapted.includes(item)) {
						adapted.push(item);
					}
				});
			});

			return adapted.length ? adapted.join(', ') + ' {' + declarations + '}' : match;
		});
	}

	function validateCSS(css) {
		if (!css) return true;

		// 1. Markup check: matches </? followed by a word character
		if (/<\/?\w+/.test(css)) {
			return false;
		}

		// 2. Imbalanced curly brackets
		const openCurly = (css.match(/{/g) || []).length;
		const closeCurly = (css.match(/}/g) || []).length;
		if (openCurly !== closeCurly) return false;

		// 3. Imbalanced square brackets
		const openSquare = (css.match(/\[/g) || []).length;
		const closeSquare = (css.match(/\]/g) || []).length;
		if (openSquare !== closeSquare) return false;

		// 4. Unterminated comment
		if (/\/\*[^*]*(?:\*(?!\/)[^*]*)*$/.test(css)) {
			return false;
		}

		// 5. Unsafe or unsupported syntax
		if (/expression\s*\(|javascript\s*:|vbscript\s*:|@import\b|behavior\s*:|-moz-binding\b/i.test(css)) {
			return false;
		}

		return true;
	}

	function injectPreviewCSS(rawCSS) {
		const css = validateCSS(rawCSS || '') ? buildEditorPreviewCSS(rawCSS) : '';
		const iframe = getEditorCanvasIframe();

		if (iframe) {
			try {
				const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;

				injectIntoDoc(iframeDoc, css);

				lastInjectedCSS = rawCSS || '';
				lastInjectedTarget = iframeDoc;
			} catch (e) {
				lastInjectedTarget = null;
			}

			const mainStyle = document.getElementById(STYLE_ID);

			if (mainStyle) {
				mainStyle.remove();
			}
		} else {
			injectIntoDoc(document, css);

			lastInjectedCSS = rawCSS || '';
			lastInjectedTarget = document;
		}
	}

	function ensurePreviewCSSInjected() {
		const iframe = getEditorCanvasIframe();
		const currentCSS = getCurrentCSS();

		if (iframe) {
			try {
				const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
				const styleExists = iframeDoc && iframeDoc.getElementById(STYLE_ID);

				if (
					iframe !== lastIframe ||
					iframeDoc !== lastInjectedTarget ||
					currentCSS !== lastInjectedCSS ||
					!styleExists
				) {
					lastIframe = iframe;

					iframe.addEventListener('load', function () {
						lastInjectedTarget = null;
						injectPreviewCSS(getCurrentCSS());
					});

					injectPreviewCSS(currentCSS);
				}
			} catch (e) { }
		} else {
			const styleExists = document.getElementById(STYLE_ID);

			if (
				document !== lastInjectedTarget ||
				currentCSS !== lastInjectedCSS ||
				!styleExists
			) {
				injectPreviewCSS(currentCSS);
			}
		}
	}

	function schedulePreviewCSSCheck() {
		if (observerScheduled) {
			return;
		}

		observerScheduled = true;

		window.requestAnimationFrame(function () {
			observerScheduled = false;
			ensurePreviewCSSInjected();
		});
	}

	const editorDomObserver = new MutationObserver(function () {
		schedulePreviewCSSCheck();
	});

	if (document.body) {
		editorDomObserver.observe(document.body, {
			childList: true,
			subtree: true
		});
	}

	const unsubscribePreviewCSS = wp.data.subscribe(function () {
		schedulePreviewCSSCheck();
	});

	window.addEventListener('load', function () {
		schedulePreviewCSSCheck();
	});

	function getInvalidCssMessage() {
		return i18n.css_invalid || 'The CSS contains a syntax error or unsupported syntax. Please check missing braces, brackets, comments, @import, javascript:, expression(), behavior:, or -moz-binding.';
	}

	function setPostSavingLocked(locked) {
		const editorDispatch = wp.data.dispatch('core/editor');

		if (!editorDispatch) {
			return;
		}

		if (locked && typeof editorDispatch.lockPostSaving === 'function') {
			editorDispatch.lockPostSaving(LOCK_NAME);
		} else if (!locked && typeof editorDispatch.unlockPostSaving === 'function') {
			editorDispatch.unlockPostSaving(LOCK_NAME);
		}
	}

	function updateCSSValidationState(css) {
		const invalid = !!css && !validateCSS(css);

		if (invalid === lastValidationInvalid) {
			return invalid;
		}

		lastValidationInvalid = invalid;
		setPostSavingLocked(invalid);

		if (!invalid) {
			const noticesDispatch = wp.data.dispatch('core/notices');

			if (noticesDispatch && typeof noticesDispatch.removeNotice === 'function') {
				noticesDispatch.removeNotice(NOTICE_ID);
			}
		}

		return invalid;
	}

	const unsubscribeValidationLock = wp.data.subscribe(function () {
		updateCSSValidationState(getCurrentCSS());
	});

	updateCSSValidationState(getCurrentCSS());

	window.addEventListener('beforeunload', function () {
		if (editorDomObserver) {
			editorDomObserver.disconnect();
		}

		if (typeof unsubscribePreviewCSS === 'function') {
			unsubscribePreviewCSS();
		}

		if (typeof unsubscribeValidationLock === 'function') {
			unsubscribeValidationLock();
		}

		setPostSavingLocked(false);
	});

	const MetaField = compose(
		withSelect(function (select) {
			const editor = select('core/editor');
			const meta = editor.getEditedPostAttribute('meta') || {};
			const postType = editor.getCurrentPostType();
			return { meta: meta, postType: postType };
		}),
		withDispatch(function (dispatch) {
			return {
				setMeta: function (value) {
					dispatch('core/editor').editPost({
						meta: { _imaginasite_per_page_css: value }
					});
				}
			};
		})
	)(function (props) {
		const editorRef = useRef(null);
		const [status, setStatus] = useState('loading');
		const [textareaElement, setTextareaElement] = useState(null);
		const [validationError, setValidationError] = useState(false);

		const textareaRefCallback = useCallback(function (node) {
			setTextareaElement(node);
		}, []);

		// List of FSE post types to dynamically exclude in the editor
		const excludedFseTypes = [
			'wp_template_part',
			'wp_global_styles',
			'wp_navigation',
			'wp_block'
		];

		// Completely hide the panel if switching to template editing
		if (excludedFseTypes.indexOf(props.postType) !== -1) {
			return null;
		}

		useEffect(function () {
			let isMounted = true;
			let localDebounceTimer = null;
			let ro = null;
			let codeEditorInstance = null;
			let waitForCodeEditorInterval = null;
			let waitForCodeEditorTimeout = null;

			if (!window.mgCodeEditorSettings) {
				setStatus(i18n.disabled);
				return;
			}

			if (!textareaElement) {
				return;
			}

			const initInstance = function () {
				if (!isMounted || !textareaElement || !window.wp.codeEditor) {
					return;
				}

				try {
					const $textarea = window.jQuery(textareaElement);

					if (
						editorRef.current &&
						editorRef.current.codemirror &&
						typeof editorRef.current.codemirror.toTextArea === 'function'
					) {
						editorRef.current.codemirror.toTextArea();
					}

					codeEditorInstance = window.wp.codeEditor.initialize(
						$textarea,
						window.mgCodeEditorSettings
					);

					editorRef.current = codeEditorInstance;

					codeEditorInstance.codemirror.on('change', function (cMirror) {
						const val = cMirror.getValue();

						props.setMeta(val);

						const invalid = updateCSSValidationState(val);
						setValidationError(invalid);

						clearTimeout(localDebounceTimer);

						localDebounceTimer = setTimeout(function () {
							injectPreviewCSS(val);
						}, 300);
					});

					setStatus('loaded');

					const initialCSS = props.meta._imaginasite_per_page_css || getCurrentCSS();
					setValidationError(updateCSSValidationState(initialCSS));
					injectPreviewCSS(initialCSS);

					if (window.ResizeObserver) {
						const wrapper = $textarea.closest('.mg-code-editor-wrapper')[0];

						if (wrapper) {
							ro = new ResizeObserver(function () {
								if (
									isMounted &&
									editorRef.current &&
									editorRef.current.codemirror
								) {
									editorRef.current.codemirror.refresh();
								}
							});

							ro.observe(wrapper);
						}
					}

					setTimeout(function () {
						if (
							isMounted &&
							editorRef.current &&
							editorRef.current.codemirror
						) {
							editorRef.current.codemirror.refresh();
						}
					}, 200);
				} catch (e) {
					setStatus(i18n.js_error + e.message);
				}
			};

			if (window.wp && window.wp.codeEditor) {
				initInstance();
			} else {
				waitForCodeEditorInterval = setInterval(function () {
					if (window.wp && window.wp.codeEditor && isMounted) {
						clearInterval(waitForCodeEditorInterval);
						waitForCodeEditorInterval = null;
						initInstance();
					}
				}, 100);

				waitForCodeEditorTimeout = setTimeout(function () {
					if (waitForCodeEditorInterval) {
						clearInterval(waitForCodeEditorInterval);
						waitForCodeEditorInterval = null;
					}

					if (isMounted) {
						setStatus(function (prev) {
							return prev === 'loading' ? i18n.timeout : prev;
						});
					}
				}, 5000);
			}

			return function () {
				isMounted = false;

				clearTimeout(localDebounceTimer);

				if (waitForCodeEditorInterval) {
					clearInterval(waitForCodeEditorInterval);
				}

				if (waitForCodeEditorTimeout) {
					clearTimeout(waitForCodeEditorTimeout);
				}

				if (ro) {
					ro.disconnect();
				}

				if (
					codeEditorInstance &&
					codeEditorInstance.codemirror &&
					typeof codeEditorInstance.codemirror.toTextArea === 'function'
				) {
					codeEditorInstance.codemirror.toTextArea();
				}

				editorRef.current = null;
				codeEditorInstance = null;
			};
		}, [textareaElement]);

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'imaginasite-per-page-css-panel',
				title: i18n.panel_title
			},
			el(
				'div',
				{
					className: 'mg-code-editor-wrapper',
					style: { marginBottom: '20px' }
				},

				status !== 'loaded'
					? el(
						'div',
						{
							style: {
								padding: '8px',
								background: '#f8f9fa',
								border: '1px solid #ccc',
								fontSize: '11px',
								marginBottom: '10px',
								borderRadius: '3px',
								fontFamily: 'monospace',
								whiteSpace: 'pre-wrap'
							}
						},
						i18n.diagnostic + '\n' +
						i18n.status + status + '\n' +
						i18n.wp_codeeditor + (window.wp && window.wp.codeEditor ? 'OK' : 'NULL') + '\n' +
						i18n.container + (textareaElement ? 'OK' : 'NULL')
					)
					: null,

				validationError
					? el(
						'div',
						{
							style: {
								padding: '8px',
								background: '#fff8e5',
								border: '1px solid #dba617',
								fontSize: '12px',
								marginBottom: '10px',
								borderRadius: '3px'
							}
						},
						getInvalidCssMessage()
					)
					: null,

				props.postType === 'wp_template'
					? el(
						'div',
						{
							style: {
								padding: '8px',
								background: '#f0f6fc',
								border: '1px solid #72aee6',
								fontSize: '12px',
								marginBottom: '10px',
								borderRadius: '3px'
							}
						},
						i18n.template_notice ||
							'This CSS will be added to the frontend only when this template is used to render the current page. It will not apply to template parts, patterns, or other templates. Note: for theme templates that have never been saved in the Site Editor, WordPress may need to create a database version of the template before custom CSS can be stored.'
					)
					: null,

				el(
					'div',
					{
						style: {
							display: status === 'loaded' ? 'block' : 'none',
							width: '100%',
							position: 'relative'
						}
					},
					el('textarea', {
						ref: textareaRefCallback,
						style: {
							width: '100%',
							minHeight: '200px',
							fontFamily: 'monospace',
							padding: '10px'
						},
						value: props.meta._imaginasite_per_page_css || '',
						onChange: function (e) {
							const val = e.target ? e.target.value : e;

							props.setMeta(val);

							const invalid = updateCSSValidationState(val);
							setValidationError(invalid);

							clearTimeout(debounceTimer);

							debounceTimer = setTimeout(function () {
								injectPreviewCSS(val);
							}, 300);
						}
					})
				),

				status !== 'loaded'
					? el('textarea', {
						style: {
							width: '100%',
							minHeight: '200px',
							fontFamily: 'monospace',
							padding: '10px',
							boxSizing: 'border-box',
							border: '1px solid #ccc',
							borderRadius: '3px'
						},
						value: props.meta._imaginasite_per_page_css || '',
						onChange: function (e) {
							const val = e.target ? e.target.value : e;

							props.setMeta(val);

							const invalid = updateCSSValidationState(val);
							setValidationError(invalid);

							clearTimeout(debounceTimer);

							debounceTimer = setTimeout(function () {
								injectPreviewCSS(val);
							}, 300);
						}
					})
					: null
			)
		);
	});

	setTimeout(function () {
		schedulePreviewCSSCheck();
	}, 500);

	registerPlugin('imaginasite-per-page-css', {
		render: MetaField
	});
})(window.wp);