# Changelog

All notable changes to AI Assistant for Craft CMS will be documented in this file.

## [Unreleased]

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
