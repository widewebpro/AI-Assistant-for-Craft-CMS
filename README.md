# AI Assistant for Craft CMS

A Craft CMS 5 plugin that adds an AI-powered chat widget to your website. Install it, configure from the admin panel — and you're live. No frontend code required.

Supports **OpenAI** and **Anthropic** as AI providers, with a built-in knowledge base (RAG), tool calling, streaming responses, and full customization from the control panel.

---

## Features

- **Embeddable chat widget** — Auto-injected into your site's frontend. Shadow DOM isolated, zero dependencies, mobile responsive.
- **Knowledge base (RAG)** — Upload PDF, DOCX, TXT, or Markdown files. The plugin chunks, embeds, and searches them semantically.
- **Two-step AI pipeline** — Step 1: validation and tool selection. Step 2: context-aware answer generation based on tool results.
- **5 built-in tools** — Knowledge base search, page context extraction, business info retrieval, topic listing, human escalation.
- **Streaming responses** — Real-time Server-Sent Events (SSE) streaming to the chat widget.
- **Multi-provider** — OpenAI (GPT-4o, GPT-4o Mini, GPT-4 Turbo) and Anthropic (Claude 3.5 Sonnet, Claude 3.7 Sonnet, Claude 3 Haiku).
- **Full admin control** — Every setting configurable from the Craft CMS control panel. No config files needed.
- **Page targeting** — Show or hide the widget on specific pages using glob URL patterns.
- **Topic restrictions** — Define allowed/disallowed topics with custom fallback messages.
- **Conversation history** — Browse, review, and manage all conversations from the admin panel.
- **Customizable appearance** — Colors, fonts, position, welcome message, custom CSS/JS injection.
- **Rate limiting** — Per-minute message limits and max messages per conversation.

---

## Requirements

- **Craft CMS** 5.0+
- **PHP** 8.2+
- An **OpenAI** or **Anthropic** API key

---

## Installation

### Via Composer (recommended)

```bash
composer require craftcms/ai-agent
```

Then install the plugin from the Craft CMS control panel under **Settings → Plugins**, or run:

```bash
php craft plugin/install ai-agent
```

### Manual / Local Development

1. Clone or download this repository into a `plugins/ai-agent` directory in your Craft project root.

2. Add the following to your project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "plugins/ai-agent"
        }
    ]
}
```

3. Require the plugin:

```bash
composer require craftcms/ai-agent:dev-main
```

4. Install the plugin:

```bash
php craft plugin/install ai-agent
```

---

## Setup

After installation, the plugin creates its own section in the Craft CMS control panel sidebar. Click on **AI Assistant** (or your custom agent name) to access:

### 1. General Settings

Navigate to **AI Assistant → Settings → General** and configure:

| Setting | Description |
|---|---|
| **Enable Widget** | Toggle the chat widget on/off across your site |
| **AI Provider** | Choose between OpenAI or Anthropic |
| **API Key** | Your provider's API key |
| **Model** | Select the AI model (GPT-4o, Claude 3.5 Sonnet, etc.) |
| **Embedding Model** | Model used for knowledge base embeddings (OpenAI) |
| **Max Tokens** | Maximum tokens per AI response (100–8192) |
| **Temperature** | Response randomness (0 = deterministic, 2 = creative) |
| **Agent Name** | Display name shown in the chat widget header |
| **Agent Persona** | System prompt defining the agent's behavior and tone |

**Business Information** — Fill in your business name, description, contact info, hours, and any extra details. The AI agent can retrieve this via the built-in `get_business_info` tool.

### 2. Appearance

Navigate to **Settings → Appearance** to customize the widget:

- **Colors** — Primary, secondary, background, and text colors with live preview
- **Widget Position** — Bottom-right or bottom-left
- **Font Family** — System default, Inter, Georgia, or Mono
- **Welcome Message** — First message shown when the chat opens
- **Placeholder Text** — Input field placeholder
- **Custom CSS** — Injected into the widget's Shadow DOM
- **Custom JavaScript** — Executed after widget initialization

### 3. Knowledge Base

Navigate to **Settings → Knowledge Base** to manage your AI's information sources:

- **Upload files** — Drag & drop or browse. Supports PDF, DOCX, TXT, and Markdown (max 10MB per file).
- **Automatic processing** — Files are parsed, split into chunks, and embedded for semantic search.
- **Manage files** — View chunk counts, status, reprocess, or delete files.

The AI agent uses the `search_knowledge_base` tool to find relevant content from uploaded files when answering questions. It does **not** inject entire files into the prompt — it performs semantic search and retrieves only the most relevant chunks.

### 4. Pages

Navigate to **Settings → Pages** to control where the widget appears:

- Add **include** or **exclude** rules using URL glob patterns
- Examples: `/products/*`, `/blog/**`, `/about`
- If no rules are defined, the widget appears on all pages

### 5. Restrictions

Navigate to **Settings → Restrictions** to control the agent's behavior:

- **Allowed Topics** — If set, the agent only discusses these topics (one per line)
- **Disallowed Topics** — The agent refuses to discuss these topics (one per line)
- **Off-Topic Fallback** — Custom message for restricted topic requests
- **Error Fallback** — Custom message for API errors or timeouts
- **Max Messages Per Conversation** — Auto-close after N messages (1–200)
- **Rate Limit** — Max messages per minute per user (1–60)

---

## How It Works

### Two-Step AI Pipeline

1. **Step 1 — Tool Selection**: The user's message is sent to the AI with a system prompt that includes available tools and knowledge base file listings. The AI decides which tools to call (search knowledge base, get page context, etc.) and does **not** answer directly.

2. **Step 2 — Answer Generation**: Tool results (knowledge base chunks, page content, business info) are injected as context. The AI generates a final, grounded answer based on real data.

This ensures the agent always retrieves information before responding, rather than hallucinating.

### Built-in Tools

| Tool | Description |
|---|---|
| `search_knowledge_base` | Semantic search across uploaded knowledge base files |
| `get_page_context` | Extracts content from the page the user is currently viewing |
| `get_business_info` | Returns business information configured in settings |
| `list_knowledge_topics` | Lists available knowledge base files so the agent knows what's available |
| `escalate` | Marks the conversation for human review |

### Knowledge Base (RAG)

The knowledge base implements Retrieval Augmented Generation:

1. **Upload** — Files are uploaded via the admin panel
2. **Parse** — Text is extracted (PDF via PDFParser, DOCX via PHPWord, plain text directly)
3. **Chunk** — Text is split into overlapping chunks for better context coverage
4. **Embed** — Each chunk is converted to a vector embedding via OpenAI's embedding API
5. **Search** — At query time, the user's question is embedded and compared via cosine similarity
6. **Fallback** — If embedding search returns no results, MySQL FULLTEXT search is used as backup

### Streaming

Responses are streamed to the browser via Server-Sent Events (SSE), so the user sees the answer being typed in real-time rather than waiting for the full response.

---

## Chat Widget

The widget is automatically injected into your site's `</body>` when enabled. It uses:

- **Shadow DOM** for complete CSS isolation (no conflicts with your site styles)
- **Vanilla JavaScript** with zero dependencies
- **localStorage** for conversation history persistence
- **CSS Custom Properties** for dynamic theming
- **EventSource** for SSE streaming

The widget is mobile responsive and includes a minimize/expand toggle.

### Custom CSS Example

Target elements inside the widget's Shadow DOM:

```css
.ai-chat-panel {
    border-radius: 20px;
}

.ai-message-bubble {
    font-size: 15px;
}
```

### Custom JavaScript Example

Runs after the widget initializes:

```javascript
console.log('AI widget loaded');
```

---

## Admin Panel

### Dashboard

Overview with quick stats: total conversations, messages, knowledge base files, and recent conversations.

### Conversations

Browse all conversations with timestamps and message counts. Click into any conversation to see the full message history.

---

## Dependencies

Installed automatically via Composer:

| Package | Purpose |
|---|---|
| `guzzlehttp/guzzle` | HTTP client for AI API calls (already included with Craft) |
| `smalot/pdfparser` | PDF text extraction for knowledge base |
| `phpoffice/phpword` | DOCX text extraction for knowledge base |

---

## API Endpoints

The plugin registers these frontend (site) routes:

| Endpoint | Method | Description |
|---|---|---|
| `/ai-agent/chat` | POST | Send a message (non-streaming) |
| `/ai-agent/chat/stream` | POST | Send a message (SSE streaming) |
| `/ai-agent/widget-config` | GET | Get widget configuration and theme |

---

## License

MIT — see [LICENSE](LICENSE) for details.

---

## Credits

Developed by [Wideweb](https://wideweb.pro)
