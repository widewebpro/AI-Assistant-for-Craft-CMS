# Changelog

All notable changes to AI Assistant for Craft CMS will be documented in this file.

## [0.3.0] - 2026-08-18

### Added
- **Site content search tool** (`search_site_content`) — the agent can search live entries by keyword (titles, URLs, snippets), with an on/off switch and optional section allow-list in Restrictions. Falls back to an OR query when a multi-word search finds nothing.
- **Deterministic page-context routing** — when a question isn't about the current page and retrieval finds nothing, the plugin itself consults the page context once before giving up; grounding rules prevent the model from re-judging topicality.
- **Robust step-1 classification via forced tool choice** — greetings/off-topic verdicts arrive as a `classify_message` tool call on both providers (works on every dropdown model), replacing fragile magic-string parsing.
- **Widget interface localization** — all widget chrome strings are served translated via Craft's translation system; the plugin ships an English key reference (`src/translations/en/craft-ai-assistant.php`) to copy into your project's `translations/<lang>/craft-ai-assistant.php`.
- **Escalation webhooks moved to the queue** — form submission responds instantly; webhook calls run in a non-retryable queue job and their results are merged into conversation metadata.
- **Max message length setting** (100–8000 chars, default 1000) — enforced in the widget and on both API endpoints.
- **Primary/Secondary text colors** — the single Text Color setting split in two (migration preserves your value).
- **Live appearance preview** — the preview now renders the widget's real markup and styles, applies Custom CSS as you type, and reflects the font family.
- **DOCX resilience** — files with Office Math formulas or EMF images no longer fail extraction (formulas inlined as text, drawings stripped, raw-XML fallback).
- **Structured rate-limit responses** — POST returns HTTP 429 with `{error, code, retryAfter}`; the stream sends the same fields in the SSE error frame. The widget shows a translatable notice and temporarily disables sending on `rate_limited`.
- Knowledge-base page: upload button disabled until a file is chosen; processing status updates automatically without reloading.

### Fixed
- Off-topic fallback no longer "sticks": refusal pairs are stripped from the model's history, so later on-topic questions recover; "look at this page"-style requests are answered from page context.
- Server error events no longer produce a duplicate error bubble in the widget.
- Conversation/message JSON columns (`metadata`, `toolCalls`, `toolResults`) are stored as real JSON; a migration repairs previously double-encoded rows; escalation metadata merges instead of overwriting.
- Relation fields no longer leak `ElementQuery` class names into content-search snippets.
- Appearance page no longer mentions SVG uploads (server never accepted them).

### Changed
- **Package renamed** to `widewebpro/craft-ai-assistant` (was `widewebpro/ai-agent`), plugin handle changed to `craft-ai-assistant`, display name to “AI Assistant”, PHP namespace to `widewebpro\aiassistant`, and DB tables to `aiassistant_*`. Existing `ai-agent` installs are treated as a different plugin: install `craft-ai-assistant` fresh (settings and data do not carry over automatically).

### Removed
- **Max Messages Per Conversation** — the per-conversation cap only produced permanently dead chats (the widget session never expires); per-session/per-IP rate limits and the daily cap remain. A migration drops the setting; conversation statuses are unaffected.

## [0.2.0] - 2026-02-23

### Added
- **Agent avatar** — Upload an image or provide a URL in the Appearance settings. Displayed in the chat header and as a 24px icon next to every assistant message. Falls back to first-letter initials when not set.
- **Dynamic escalation form builder** — Contact Form Fields are now defined via a native Craft editable table. Each field has a label, handle, type (text, email, phone, textarea, select, checkbox), required flag, placeholder, and options column. Replaces the hardcoded Name/Email/Phone checkboxes and custom questions textarea.
- **Webhook / CRM integration** — New "Submission Actions" section in Escalation settings. Configure any number of webhook endpoints with name, URL, HTTP method (POST/PUT/PATCH), format (JSON/form-encoded), and custom headers. All enabled actions fire automatically when an escalation form is submitted.
- **Field mapping** — Separate "Field Mapping" table maps form handles to external field names. Supports dot notation for nested payloads (e.g. `email` → `properties.email`). Available form handles are shown as a quick reference below the mapping table.
- **Native Craft CP patterns** — All settings pages now use `fullPageForm`, providing a native Save button in the header, Cmd+S / Ctrl+S keyboard shortcut, and unsaved-changes warnings. Tabs render via Craft's native header tab system. Conversation detail view uses native breadcrumbs.
- **Escalation lightswitch toggle** — Uses Craft's native `toggle` attribute instead of custom JavaScript.
- **WebhookService** — New service that handles building payloads, parsing field maps, resolving nested keys via dot notation, and firing Guzzle requests with error logging. Webhook results are saved to conversation metadata.

### Changed
- Escalation settings restructured: "Contact Form Fields" and "Custom Questions" replaced by a single editable table. "Submission Actions" and "Field Mapping" are new dedicated sections.
- Settings page titles simplified to "Settings" (tab indicates which section).
- All settings templates refactored to use `{% set fullPageForm = true %}` with `actionInput`/`redirectInput` inside `{% block content %}`.
- Settings layout uses Craft's native `tabs` variable instead of custom HTML navigation.
- Conversations detail view uses native `crumbs` instead of a manual back button.

### Fixed
- `foreach()` error when editable tables submit empty string instead of array (Craft's hidden input fallback). Added `_ensureArray()` guards in controller and `is_array()` checks in Settings model `init()`.

## [0.1.1] - 2026-02-19

### Added
- **Escalation system** — Configurable human handoff with inline contact form in the chat widget.
  - Enable/disable escalation tool from the admin panel.
  - Escalation sensitivity setting (Low / Medium / High) controls how readily the agent escalates.
  - Configurable contact form fields: Name, Email, Phone.
  - Custom questions support for business-specific fields.
  - Escalation and confirmation messages configurable from CMS.
  - Contact form data saved to conversation metadata, viewable in admin.
  - Dedicated **Escalation** tab in Settings.
- **Smart message classification** — Messages are now classified into four categories (greeting, question, off-topic, escalation) instead of forcing all messages through the tool-calling pipeline.
  - Greetings ("hello", "hi", "thanks") get a natural conversational response without triggering tools.
  - Off-topic detection is more precise — casual messages no longer trigger the fallback.
- **Escalation sensitivity** — New setting under Settings → Escalation that controls how easily the agent agrees to escalate:
  - **Low**: Only when user explicitly demands a human.
  - **Medium**: When user clearly asks for human help (default).
  - **High**: Also when user seems frustrated or agent fails repeatedly.

### Fixed
- Escalation status not being saved to database in streaming mode (tool call data key mismatch).
- "I need help" no longer immediately triggers escalation — agent tries to help first.
- "Hello" and other greetings no longer trigger the off-topic fallback message.

### Changed
- Settings navigation restructured: main sidebar now shows Dashboard, Conversations, and Settings. All configuration lives under Settings with tabbed sub-navigation (General, Appearance, Knowledge Base, Pages, Restrictions, Escalation).
- Plugin renamed to "AI Assistant for Craft CMS".
- Vendor/namespace changed from `craftcms/ai-assistant` to `widewebpro/ai-agent`.
- Developer info updated to Wideweb (https://wideweb.pro).
- Widget asset loading now uses filesystem path instead of Yii alias for reliability across installations.

## [0.1.0] - 2026-02-19

### Added
- Initial release.
- AI-powered chat widget with Shadow DOM isolation and vanilla JS.
- Two-step AI pipeline: tool selection → context-aware answer generation.
- OpenAI and Anthropic provider support.
- Knowledge base with RAG: PDF, DOCX, TXT, Markdown file upload, chunking, embedding, and semantic search.
- 5 built-in tools: search_knowledge_base, get_page_context, get_business_info, list_knowledge_topics, escalate.
- SSE streaming responses.
- Full admin panel: Dashboard, Conversations viewer, Settings.
- Appearance customization: colors, fonts, position, custom CSS/JS, live preview.
- Page targeting with glob URL patterns.
- Topic restrictions with allowed/disallowed topics and fallback messages.
- Rate limiting per minute and per conversation.
- Conversation history with message threading.
