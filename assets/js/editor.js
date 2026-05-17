(function (wp) {
	const i18n = window.imaginasitePerPageCssData.i18n;
	window.mgCodeEditorSettings = window.imaginasitePerPageCssData.settings;

	const el = wp.element.createElement;
	const PluginDocumentSettingPanel =
		(wp.editor && wp.editor.PluginDocumentSettingPanel) ||
		(wp.editPost && wp.editPost.PluginDocumentSettingPanel) ||
		null;

	if (!PluginDocumentSettingPanel) {
		return;
	}
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

	const META_KEY = window.imaginasitePerPageCssData.meta_key || '_imaginasite_per_page_css';

	const EXCLUDED_FSE_TYPES = [
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_block'
	];

	let lastInjectedCSS = null;
	let debounceTimer = null;
	let lastValidationInvalid = null;
	let boundIframe = null;

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

	function getEditorStore() {
		try {
			if (wp.data.select('core/edit-site')) {
				return 'core/edit-site';
			}
		} catch (e) {
			console.warn('Imaginasite Per Page CSS - Error checking core/edit-site:', e);
		}

		try {
			if (wp.data.select('core/editor')) {
				return 'core/editor';
			}
		} catch (e) {
			console.warn('Imaginasite Per Page CSS - Error checking core/editor:', e);
		}

		return null;
	}

	function isExcludedSiteEditorUrl() {
		const url = window.location.href;

		return (
			url.indexOf('postType=wp_template_part') !== -1 ||
			url.indexOf('/patterns') !== -1 ||
			url.indexOf('categoryId=template-parts') !== -1 ||
			url.indexOf('template-part') !== -1
		);
	}

	function isExcludedEditorContext(editor) {
		if (isExcludedSiteEditorUrl()) {
			return true;
		}

		if (!editor) {
			return true;
		}

		try {
			const postType =
				typeof editor.getCurrentPostType === 'function'
					? editor.getCurrentPostType()
					: '';

			if (EXCLUDED_FSE_TYPES.indexOf(postType) !== -1) {
				return true;
			}

			if (typeof editor.getEditedPostAttribute === 'function') {
				const type = editor.getEditedPostAttribute('type');
				const slug = editor.getEditedPostAttribute('slug');
				const id = editor.getEditedPostAttribute('id');

				if (EXCLUDED_FSE_TYPES.indexOf(type) !== -1) {
					return true;
				}

				if (
					String(type || '').indexOf('wp_template_part') !== -1 ||
					String(slug || '').indexOf('header') !== -1 ||
					String(slug || '').indexOf('footer') !== -1 ||
					String(id || '').indexOf('wp_template_part') !== -1 ||
					String(id || '').indexOf('//header') !== -1 ||
					String(id || '').indexOf('//footer') !== -1
				) {
					return true;
				}
			}
		} catch (e) {
			console.warn('Imaginasite Per Page CSS - Error checking editor context:', e);
			return true;
		}

		return false;
	}

	function getCurrentCSS() {
		const store = getEditorStore();
		if (!store) return '';

		try {
			const editor = wp.data.select(store);
			if (!editor) return '';

			const postType = (typeof editor.getCurrentPostType === 'function') 
				? editor.getCurrentPostType() 
				: '';

			if (isExcludedEditorContext(editor)) {
				return '';
			}

			if (typeof editor.getEditedPostAttribute === 'function') {
				if (postType === 'wp_template') {
					return editor.getEditedPostAttribute(META_KEY) || '';
				}

				const meta = editor.getEditedPostAttribute('meta') || {};
				return meta[META_KEY] || '';
			}
		} catch (e) {
			console.warn('Imaginasite Per Page CSS - Error getting current CSS:', e);
		}

		return '';
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

	function injectPreviewCSS(rawCSS, force) {
		const cssValue = rawCSS || '';
		const iframe = getEditorCanvasIframe();

		let targetDoc = document;
		let usingIframe = false;

		if (iframe) {
			try {
				targetDoc = iframe.contentDocument || iframe.contentWindow.document;
				usingIframe = true;
			} catch (e) {
				targetDoc = document;
				usingIframe = false;
			}
		}

		const existingStyle = targetDoc && targetDoc.getElementById(STYLE_ID);

		if (!force && cssValue === lastInjectedCSS && existingStyle) {
			return;
		}

		const css = validateCSS(cssValue) ? buildEditorPreviewCSS(cssValue) : '';

		injectIntoDoc(targetDoc, css);

		if (usingIframe) {
			if (iframe !== boundIframe) {
				boundIframe = iframe;

				iframe.addEventListener('load', function () {
					lastInjectedCSS = null;
					injectPreviewCSS(getCurrentCSS(), true);
				});
			}

			const mainStyle = document.getElementById(STYLE_ID);

			if (mainStyle) {
				mainStyle.remove();
			}
		}

		lastInjectedCSS = cssValue;
	}



	const unsubscribePreviewCSS = wp.data.subscribe(function () {
		clearTimeout(debounceTimer);

		debounceTimer = setTimeout(function () {
			injectPreviewCSS(getCurrentCSS());
		}, 300);
	});

	window.addEventListener('load', function () {
		injectPreviewCSS(getCurrentCSS(), true);
	});

	function getInvalidCssMessage() {
		return i18n.css_invalid || 'Invalid or unsupported CSS syntax detected. Please check your code.';
	}

	function setPostSavingLocked(locked) {
		const store = getEditorStore();
		if (!store) return;

		try {
			const editor = wp.data.select(store);
			if (!editor) return;

			const postType = (typeof editor.getCurrentPostType === 'function') 
				? editor.getCurrentPostType() 
				: '';

			if (isExcludedEditorContext(editor)) {
				return;
			}

			const editorDispatch = wp.data.dispatch(store);

			if (!editorDispatch) {
				return;
			}

			if (locked && typeof editorDispatch.lockPostSaving === 'function') {
				editorDispatch.lockPostSaving(LOCK_NAME);
			} else if (!locked && typeof editorDispatch.unlockPostSaving === 'function') {
				editorDispatch.unlockPostSaving(LOCK_NAME);
			}
		} catch (e) {
			console.warn('Imaginasite Per Page CSS - Error setting post saving lock:', e);
		}
	}

	function updateCSSValidationState(css) {
		const invalid = !!css && !validateCSS(css);

		if (invalid === lastValidationInvalid) {
			return invalid;
		}

		lastValidationInvalid = invalid;
		setPostSavingLocked(invalid);



		return invalid;
	}

	const unsubscribeValidationLock = wp.data.subscribe(function () {
		updateCSSValidationState(getCurrentCSS());
	});

	updateCSSValidationState(getCurrentCSS());

	window.addEventListener('beforeunload', function () {
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
			const store = getEditorStore();
			if (!store) return { cssValue: '', postType: '', store: null };

			try {
				const editor = select(store);
				if (!editor) return { cssValue: '', postType: '', store: null };

				const postType = (typeof editor.getCurrentPostType === 'function') 
					? editor.getCurrentPostType() 
					: '';

				if (isExcludedEditorContext(editor)) {
					return { cssValue: '', postType: postType, store: null };
				}

				let cssValue = '';

				if (typeof editor.getEditedPostAttribute === 'function') {
					if (postType === 'wp_template') {
						cssValue = editor.getEditedPostAttribute(META_KEY) || '';
					} else {
						const meta = editor.getEditedPostAttribute('meta') || {};
						cssValue = meta[META_KEY] || '';
					}
				}

				return { cssValue: cssValue, postType: postType, store: store };
			} catch (e) {
				console.warn('Imaginasite Per Page CSS - Error in withSelect:', e);
				return { cssValue: '', postType: '', store: null };
			}
		}),
		withDispatch(function (dispatch) {
			return {
				setMeta: function (value, postType, store) {
					if (!store) return;
					
					try {
						const editorDispatch = dispatch(store);
						if (!editorDispatch || typeof editorDispatch.editPost !== 'function') return;

						if (postType === 'wp_template') {
							editorDispatch.editPost({
								[META_KEY]: value
							});
						} else {
							editorDispatch.editPost({
								meta: { [META_KEY]: value }
							});
						}
					} catch (e) {
						console.warn('Imaginasite Per Page CSS - Error in setMeta dispatch:', e);
					}
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

		// Completely hide the panel if switching to template editing
		if (!props.store || isExcludedSiteEditorUrl()) {
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

						props.setMeta(val, props.postType, props.store);

						const invalid = updateCSSValidationState(val);
						setValidationError(invalid);

						clearTimeout(localDebounceTimer);

						localDebounceTimer = setTimeout(function () {
							injectPreviewCSS(val);
						}, 300);
					});

					setStatus('loaded');

					const initialCSS = props.cssValue || getCurrentCSS();
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

		if (!PluginDocumentSettingPanel) return null;

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
							'This CSS applies only when this template renders the current page. Unsaved theme templates must be saved first.'
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
						value: props.cssValue || '',
						onChange: function (e) {
							const val = e.target ? e.target.value : e;

							props.setMeta(val, props.postType, props.store);

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
						value: props.cssValue || '',
						onChange: function (e) {
							const val = e.target ? e.target.value : e;

							props.setMeta(val, props.postType, props.store);

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
		injectPreviewCSS(getCurrentCSS(), true);
	}, 500);



	registerPlugin('imaginasite-per-page-css', {
		render: MetaField
	});
})(window.wp);