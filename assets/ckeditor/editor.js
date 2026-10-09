/**
 * This configuration was generated using the CKEditor 5 Builder. You can modify it anytime using this link:
 * https://ckeditor.com/ckeditor-5/builder/#installation/NoRgLANARAzAdCOMrRCAbATgEwAYQzrbYwAcArLjuWKejCJjLrurjWWNuedqa1kxh0NdGBRQApgDsUuCKAgglEecpABdaN2wATXDABGUDUA=
 */

const {
	ClassicEditor,
	Autosave,
	Essentials,
	Paragraph,
	Autoformat,
	TextTransformation,
	Bold,
	Table,
	TableToolbar,
	Emoji,
	Mention,
	FontBackgroundColor,
	FontColor,
	FontFamily,
	FontSize,
	GeneralHtmlSupport,
	Heading,
	ImageInline,
	ImageToolbar,
	CloudServices,
	Link,
	ImageUpload,
	ImageInsertViaUrl,
	AutoImage,
	ImageTextAlternative,
	ImageStyle,
	Indent,
	IndentBlock,
	Italic,
	AutoLink,
	List,
	ImageUtils,
	ImageEditing,
	PlainTableOutput,
	Strikethrough,
	Style,
	TableCaption,
	Alignment,
	Underline,
	Fullscreen,
	MediaEmbed,
	MediaEmbedStyle,
	MediaEmbedToolbar,
	Code,
	Subscript,
	Superscript,
	Highlight,
	BlockQuote,
	HorizontalLine,
	CodeBlock,
	ImageBlock,
	LinkImage,
	ImageCaption,
	ShowBlocks,
	SourceEditing,
	HtmlComment,
	TodoList,
	BalloonToolbar,
	BlockToolbar
} = window.CKEDITOR;

const LICENSE_KEY = 'GPL';

// The emoji list is served by this site (public_html/vendor/ckeditor5/emoji), not
// CKEditor's CDN: the Content-Security-Policy allows connections to the site only.
// CKEditor needs an absolute URL.
const EMOJI_DEFINITIONS = new URL(document.currentScript.dataset.emojiDefinitions, document.baseURI).href;

const editorConfig = {
	attachTo: document.querySelector('[data-html-editor]'),
	root: {
		placeholder: ''
	},
	toolbar: {
		items: [
			'undo',
			'redo',
			'|',
			'sourceEditing',
			'showBlocks',
			'fullscreen',
			'|',
			'heading',
			'style',
			'|',
			'fontSize',
			'fontFamily',
			'fontColor',
			'fontBackgroundColor',
			'|',
			'bold',
			'italic',
			'underline',
			'strikethrough',
			'subscript',
			'superscript',
			'code',
			'|',
			'emoji',
			'horizontalLine',
			'link',
			'insertImageViaUrl',
			'mediaEmbed',
			'insertTable',
			'highlight',
			'blockQuote',
			'codeBlock',
			'|',
			'alignment',
			'|',
			'bulletedList',
			'numberedList',
			'todoList',
			'outdent',
			'indent'
		],
		shouldNotGroupWhenFull: true
	},
	plugins: [
		Alignment,
		Autoformat,
		AutoImage,
		AutoLink,
		Autosave,
		BalloonToolbar,
		BlockQuote,
		BlockToolbar,
		Bold,
		CloudServices,
		Code,
		CodeBlock,
		Emoji,
		Essentials,
		FontBackgroundColor,
		FontColor,
		FontFamily,
		FontSize,
		Fullscreen,
		GeneralHtmlSupport,
		Heading,
		Highlight,
		HorizontalLine,
		HtmlComment,
		ImageBlock,
		ImageCaption,
		ImageEditing,
		ImageInline,
		ImageInsertViaUrl,
		ImageStyle,
		ImageTextAlternative,
		ImageToolbar,
		ImageUpload,
		ImageUtils,
		Indent,
		IndentBlock,
		Italic,
		Link,
		LinkImage,
		List,
		MediaEmbed,
		MediaEmbedStyle,
		MediaEmbedToolbar,
		Mention,
		Paragraph,
		PlainTableOutput,
		ShowBlocks,
		SourceEditing,
		Strikethrough,
		Style,
		Subscript,
		Superscript,
		Table,
		TableCaption,
		TableToolbar,
		TextTransformation,
		TodoList,
		Underline
	],
	licenseKey: LICENSE_KEY,
	emoji: {
		definitionsUrl: EMOJI_DEFINITIONS
	},
	autosave: {
		/* See: https://ckeditor.com/docs/ckeditor5/latest/features/autosave.html */
	},
	balloonToolbar: ['bold', 'italic', '|', 'link', '|', 'bulletedList', 'numberedList'],
	blockToolbar: [
		'fontSize',
		'fontColor',
		'fontBackgroundColor',
		'|',
		'bold',
		'italic',
		'|',
		'link',
		'insertTable',
		'|',
		'bulletedList',
		'numberedList',
		'outdent',
		'indent'
	],
	fontFamily: {
		supportAllValues: true
	},
	fontSize: {
		options: [10, 12, 14, 'default', 18, 20, 22],
		supportAllValues: true
	},
	fullscreen: {
		onEnterCallback: container =>
			container.classList.add(
				'editor-container',
				'editor-container_classic-editor',
				'editor-container_include-style',
				'editor-container_include-block-toolbar',
				'editor-container_include-fullscreen',
				'main-container'
			)
	},
	heading: {
		options: [
			{
				model: 'paragraph',
				title: 'Paragraph',
				class: 'ck-heading_paragraph'
			},
			{
				model: 'heading1',
				view: 'h1',
				title: 'Heading 1',
				class: 'ck-heading_heading1'
			},
			{
				model: 'heading2',
				view: 'h2',
				title: 'Heading 2',
				class: 'ck-heading_heading2'
			},
			{
				model: 'heading3',
				view: 'h3',
				title: 'Heading 3',
				class: 'ck-heading_heading3'
			},
			{
				model: 'heading4',
				view: 'h4',
				title: 'Heading 4',
				class: 'ck-heading_heading4'
			},
			{
				model: 'heading5',
				view: 'h5',
				title: 'Heading 5',
				class: 'ck-heading_heading5'
			},
			{
				model: 'heading6',
				view: 'h6',
				title: 'Heading 6',
				class: 'ck-heading_heading6'
			}
		]
	},
	htmlSupport: {
		allow: [
			{
				name: /^.*$/,
				styles: true,
				attributes: true,
				classes: true
			}
		],
		// Email clients run none of these; keeping them out of drafts avoids
		// surprises. The site's own pages sanitise message HTML regardless
		// (config/packages/html_sanitizer.yaml).
		disallow: [
			{ name: /^(script|iframe|object|embed|form|input|button|select|textarea)$/ },
			{ name: /^.*$/, attributes: [{ key: /^on/i, value: true }] }
		]
	},
	image: {
		toolbar: ['toggleImageCaption', 'imageTextAlternative', '|', 'imageStyle:inline', 'imageStyle:wrapText', 'imageStyle:breakText']
	},
	link: {
		addTargetToExternalLinks: true,
		defaultProtocol: 'https://',
		decorators: {
			toggleDownloadable: {
				mode: 'manual',
				label: 'Downloadable',
				attributes: {
					download: 'file'
				}
			}
		}
	},
	mediaEmbed: {
		toolbar: ['mediaEmbed:breakText', 'mediaEmbed:wrapText']
	},
	mention: {
		feeds: [
			{
				marker: '@',
				feed: [
					/* See: https://ckeditor.com/docs/ckeditor5/latest/features/mentions.html */
				]
			}
		]
	},
	menuBar: {
		isVisible: true
	},
	style: {
		definitions: [
			{
				name: 'Article category',
				element: 'h3',
				classes: ['category']
			},
			{
				name: 'Title',
				element: 'h2',
				classes: ['document-title']
			},
			{
				name: 'Subtitle',
				element: 'h3',
				classes: ['document-subtitle']
			},
			{
				name: 'Info box',
				element: 'p',
				classes: ['info-box']
			},
			{
				name: 'CTA Link Primary',
				element: 'a',
				classes: ['button', 'button--green']
			},
			{
				name: 'CTA Link Secondary',
				element: 'a',
				classes: ['button', 'button--black']
			},
			{
				name: 'Marker',
				element: 'span',
				classes: ['marker']
			},
			{
				name: 'Spoiler',
				element: 'span',
				classes: ['spoiler']
			}
		]
	},
	table: {
		contentToolbar: ['tableColumn', 'tableRow', 'mergeTableCells']
	}
};

ClassicEditor.create(editorConfig);
