# Changelog

All notable changes to AI Assistant for Craft CMS will be documented in this file.

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
- Vendor/namespace changed from `craftcms/ai-agent` to `widewebpro/ai-agent`.
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
