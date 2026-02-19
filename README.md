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
- **Full admin control** — Every setting configurable from the Craft CMS control panel.
- **Page targeting** — Show or hide the widget on specific pages using glob URL patterns.
- **Topic restrictions** — Define allowed/disallowed topics with custom fallback messages.
- **Conversation history** — Browse and review all conversations from the admin panel.
- **Customizable appearance** — Colors, fonts, position, welcome message, custom CSS/JS injection.
- **Rate limiting** — Per-minute message limits and max messages per conversation.

---

## Requirements

- Craft CMS 5.0+
- PHP 8.2+
- An OpenAI or Anthropic API key

---

## Installation

```bash
composer require widewebpro/ai-agent
```

Then install from the Craft control panel under **Settings → Plugins**, or run:

```bash
php craft plugin/install ai-agent
```

---

## Setup

After installation, the plugin appears in the control panel sidebar. Click it to access the **Dashboard**, **Conversations**, and **Settings**.

All configuration is done under **Settings**, which contains five tabs:

### General

| Setting | Description |
|---|---|
| Enable Widget | Toggle the chat widget on/off across your site |
| AI Provider | OpenAI or Anthropic |
| API Key | Your provider's API key |
| Model | GPT-4o, GPT-4o Mini, GPT-4 Turbo, Claude 3.5 Sonnet, Claude 3.7 Sonnet, Claude 3 Haiku |
| Embedding Model | Model used for knowledge base embeddings (OpenAI) |
| Max Tokens | Maximum tokens per response (100–8192) |
| Temperature | Randomness (0 = deterministic, 2 = creative) |
| Agent Name | Display name in the widget header |
| Agent Persona | System prompt defining the agent's behavior and tone |
| Business Info | Name, description, contact, hours — retrievable by the AI via tools |

### Appearance

Customize the widget look and feel with a live preview:

- **Colors** — Primary, secondary, background, text
- **Position** — Bottom-right or bottom-left
- **Font** — System default, Inter, Georgia, Mono
- **Welcome message** and **placeholder text**
- **Custom CSS** — Injected into the widget's Shadow DOM for full control
- **Custom JavaScript** — Runs after widget initialization

### Knowledge Base

Upload documents to build your AI's information sources:

- Drag & drop or browse — supports **PDF, DOCX, TXT, Markdown** (max 10MB per file)
- Files are automatically parsed, chunked, and embedded for semantic search
- Reprocess or delete files at any time

The AI agent does **not** inject entire files into prompts. It performs semantic search and retrieves only the most relevant chunks per question.

### Pages

Control where the widget appears using URL glob patterns:

- Add **include** or **exclude** rules (e.g. `/products/*`, `/blog/**`, `/contact`)
- No rules = widget appears on all pages

### Restrictions

Control the agent's behavior boundaries:

- **Allowed topics** — If set, the agent only discusses these (one per line)
- **Disallowed topics** — The agent refuses these (one per line)
- **Fallback messages** — Custom responses for off-topic and error scenarios
- **Rate limiting** — Max messages per minute and per conversation

---

## How It Works

### Two-Step AI Pipeline

1. **Tool Selection** — The user's message is analyzed. The AI selects which tools to call (search knowledge base, get page context, etc.) without answering directly.
2. **Answer Generation** — Tool results are injected as context. The AI generates a grounded answer based on retrieved data.

This prevents hallucination by ensuring the agent always retrieves information before responding.

### Built-in Tools

| Tool | What it does |
|---|---|
| `search_knowledge_base` | Semantic search across uploaded files |
| `get_page_context` | Extracts content from the page the user is currently on |
| `get_business_info` | Returns business information from settings |
| `list_knowledge_topics` | Lists available knowledge base files |
| `escalate` | Marks the conversation for human review |

### Knowledge Base (RAG)

1. Files are uploaded and text is extracted (PDF, DOCX, TXT, MD)
2. Text is split into overlapping chunks
3. Each chunk is embedded via OpenAI's embedding API
4. At query time, the question is embedded and matched via cosine similarity
5. If no embedding match, MySQL FULLTEXT search is used as fallback

### Chat Widget

- **Shadow DOM** for complete CSS isolation from your site
- **Vanilla JS** — zero frontend dependencies
- **SSE streaming** — responses appear in real-time
- **localStorage** — conversation history persists across page loads
- Mobile responsive with minimize/expand toggle

---

## Admin Panel

**Dashboard** — Quick stats: total conversations, messages, knowledge base files, and recent activity.

**Conversations** — Full history of all user conversations. Click into any to read the complete thread.

---

## License

MIT — see [LICENSE](LICENSE).

---

Built by [Wideweb](https://wideweb.pro)
