(function () {
  'use strict';

  var config = window.__aiAgentConfig;
  if (!config || !config.enabled) return;

  // Page rule matching
  if (config.pageRules && config.pageRules.length > 0) {
    var path = window.location.pathname;
    var hasIncludes = config.pageRules.some(function (r) { return r.ruleType === 'include'; });
    var allowed = !hasIncludes;

    for (var i = 0; i < config.pageRules.length; i++) {
      var rule = config.pageRules[i];
      if (matchGlob(rule.pattern, path)) {
        allowed = rule.ruleType === 'include';
      }
    }

    if (!allowed) return;
  }

  var sessionId = getSessionId();
  var isOpen = false;
  var isStreaming = false;
  var messages = loadMessages();

  // Create host element
  var host = document.createElement('div');
  host.id = 'ai-agent-widget';
  document.body.appendChild(host);

  var shadow = host.attachShadow({ mode: 'open' });

  // Inject styles
  var style = document.createElement('style');
  style.textContent = getStyles();
  shadow.appendChild(style);

  if (config.customCss) {
    var customStyle = document.createElement('style');
    customStyle.textContent = config.customCss;
    shadow.appendChild(customStyle);
  }

  // Build DOM
  var container = document.createElement('div');
  container.className = 'ai-widget';
  container.setAttribute('role', 'complementary');
  container.setAttribute('aria-label', config.agentName + ' Chat');
  shadow.appendChild(container);

  // Toggle button
  var toggleBtn = document.createElement('button');
  toggleBtn.className = 'ai-toggle';
  toggleBtn.setAttribute('aria-label', 'Open chat');
  toggleBtn.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>';
  container.appendChild(toggleBtn);

  // Chat panel
  var panel = document.createElement('div');
  panel.className = 'ai-panel';
  panel.setAttribute('aria-hidden', 'true');
  container.appendChild(panel);

  // Header
  var header = document.createElement('div');
  header.className = 'ai-header';
  header.innerHTML = '<div class="ai-header-info"><div class="ai-avatar">' +
    config.agentName.charAt(0).toUpperCase() +
    '</div><div><div class="ai-name">' + escapeHtml(config.agentName) +
    '</div><div class="ai-status">Online</div></div></div>' +
    '<button class="ai-close" aria-label="Close chat">&times;</button>';
  panel.appendChild(header);

  // Messages area
  var messagesEl = document.createElement('div');
  messagesEl.className = 'ai-messages';
  messagesEl.setAttribute('role', 'log');
  messagesEl.setAttribute('aria-live', 'polite');
  panel.appendChild(messagesEl);

  // Input area
  var inputArea = document.createElement('div');
  inputArea.className = 'ai-input-area';
  inputArea.innerHTML = '<textarea class="ai-input" placeholder="' +
    escapeHtml(config.placeholderText) +
    '" rows="1" aria-label="Message"></textarea>' +
    '<button class="ai-send" aria-label="Send message">' +
    '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg></button>';
  panel.appendChild(inputArea);

  var input = inputArea.querySelector('.ai-input');
  var sendBtn = inputArea.querySelector('.ai-send');
  var closeBtn = header.querySelector('.ai-close');

  // Render initial messages
  if (messages.length === 0 && config.welcomeMessage) {
    addBotMessage(config.welcomeMessage, false);
  } else {
    messages.forEach(function (msg) {
      appendMessageEl(msg.role, msg.content);
    });
  }

  // Events
  toggleBtn.addEventListener('click', function () { togglePanel(true); });
  closeBtn.addEventListener('click', function () { togglePanel(false); });

  sendBtn.addEventListener('click', sendMessage);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  });

  input.addEventListener('input', function () {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
  });

  // Custom JS
  if (config.customJs) {
    try { new Function(config.customJs)(); } catch (e) { console.warn('AI Agent custom JS error:', e); }
  }

  // ─── Functions ──────────────────────────────────────

  function togglePanel(open) {
    isOpen = open;
    panel.classList.toggle('open', open);
    panel.setAttribute('aria-hidden', !open);
    toggleBtn.style.display = open ? 'none' : '';
    if (open) {
      scrollToBottom();
      input.focus();
    }
  }

  function sendMessage() {
    var text = input.value.trim();
    if (!text || isStreaming) return;

    addUserMessage(text);
    input.value = '';
    input.style.height = 'auto';

    streamResponse(text);
  }

  function addUserMessage(text) {
    messages.push({ role: 'user', content: text });
    saveMessages();
    appendMessageEl('user', text);
    scrollToBottom();
  }

  function addBotMessage(text, save) {
    if (save !== false) {
      messages.push({ role: 'assistant', content: text });
      saveMessages();
    }
    appendMessageEl('assistant', text);
    scrollToBottom();
  }

  function appendMessageEl(role, content) {
    var wrapper = document.createElement('div');
    wrapper.className = 'ai-msg ai-msg-' + role;

    var bubble = document.createElement('div');
    bubble.className = 'ai-bubble';
    bubble.innerHTML = renderMarkdown(content);

    wrapper.appendChild(bubble);
    messagesEl.appendChild(wrapper);
  }

  function streamResponse(text) {
    isStreaming = true;

    // Show typing indicator
    var typingEl = document.createElement('div');
    typingEl.className = 'ai-msg ai-msg-assistant';
    typingEl.innerHTML = '<div class="ai-bubble ai-typing"><span></span><span></span><span></span></div>';
    messagesEl.appendChild(typingEl);
    scrollToBottom();

    var url = config.endpoints.stream +
      '?message=' + encodeURIComponent(text) +
      '&sessionId=' + encodeURIComponent(sessionId) +
      '&pageUrl=' + encodeURIComponent(window.location.href);

    var fullText = '';
    var bubbleEl = null;

    var eventSource = new EventSource(url);

    eventSource.addEventListener('token', function (e) {
      var data = JSON.parse(e.data);

      if (!bubbleEl) {
        messagesEl.removeChild(typingEl);
        var wrapper = document.createElement('div');
        wrapper.className = 'ai-msg ai-msg-assistant';
        bubbleEl = document.createElement('div');
        bubbleEl.className = 'ai-bubble';
        wrapper.appendChild(bubbleEl);
        messagesEl.appendChild(wrapper);
      }

      fullText += data.delta;
      bubbleEl.innerHTML = renderMarkdown(fullText);
      scrollToBottom();
    });

    eventSource.addEventListener('tool_call', function (e) {
      var data = JSON.parse(e.data);
      var indicator = document.createElement('div');
      indicator.className = 'ai-tool-indicator';
      indicator.textContent = 'Searching: ' + data.tool + '...';
      if (typingEl.parentNode) {
        messagesEl.insertBefore(indicator, typingEl);
      } else {
        messagesEl.appendChild(indicator);
      }
      scrollToBottom();
    });

    eventSource.addEventListener('error', function (e) {
      var data;
      try { data = JSON.parse(e.data); } catch (ex) { data = { message: config.errorMessage || 'An error occurred.' }; }

      if (!bubbleEl) {
        messagesEl.removeChild(typingEl);
      }
      addBotMessage(data.message || 'An error occurred.');
      isStreaming = false;
      eventSource.close();
    });

    eventSource.addEventListener('escalation', function (e) {
      if (config.escalation && config.escalation.enabled) {
        showEscalationForm();
      }
    });

    eventSource.addEventListener('done', function (e) {
      if (!bubbleEl && typingEl.parentNode) {
        messagesEl.removeChild(typingEl);
      }

      if (fullText) {
        messages.push({ role: 'assistant', content: fullText });
        saveMessages();
      }

      isStreaming = false;
      eventSource.close();
    });

    eventSource.onerror = function () {
      if (typingEl.parentNode) {
        messagesEl.removeChild(typingEl);
      }
      if (!fullText) {
        addBotMessage(config.errorMessage || 'Connection lost. Please try again.');
      } else {
        messages.push({ role: 'assistant', content: fullText });
        saveMessages();
      }
      isStreaming = false;
      eventSource.close();
    };
  }

  function showEscalationForm() {
    var esc = config.escalation || {};
    var fields = esc.fields || {};
    var customQs = esc.customQuestions || [];

    var formWrapper = document.createElement('div');
    formWrapper.className = 'ai-msg ai-msg-assistant';

    var formBubble = document.createElement('div');
    formBubble.className = 'ai-bubble ai-escalation-form';

    var html = '<div style="font-weight:600;margin-bottom:8px;">Contact Information</div>';

    if (fields.name) {
      html += '<div class="ai-esc-field"><label>Name</label><input type="text" name="name" placeholder="Your name" class="ai-esc-input"></div>';
    }
    if (fields.email) {
      html += '<div class="ai-esc-field"><label>Email</label><input type="email" name="email" placeholder="your@email.com" class="ai-esc-input"></div>';
    }
    if (fields.phone) {
      html += '<div class="ai-esc-field"><label>Phone</label><input type="tel" name="phone" placeholder="Phone number" class="ai-esc-input"></div>';
    }

    for (var q = 0; q < customQs.length; q++) {
      var qLabel = customQs[q];
      var qKey = 'custom_' + q;
      html += '<div class="ai-esc-field"><label>' + escapeHtml(qLabel) + '</label><input type="text" name="' + qKey + '" placeholder="' + escapeHtml(qLabel) + '" class="ai-esc-input"></div>';
    }

    html += '<button type="button" class="ai-esc-submit">Submit</button>';
    formBubble.innerHTML = html;
    formWrapper.appendChild(formBubble);
    messagesEl.appendChild(formWrapper);
    scrollToBottom();

    var submitBtn = formBubble.querySelector('.ai-esc-submit');
    submitBtn.addEventListener('click', function () {
      var inputs = formBubble.querySelectorAll('.ai-esc-input');
      var contactData = {};
      var hasRequired = true;

      inputs.forEach(function (inp) {
        var val = inp.value.trim();
        contactData[inp.name] = val;
        if (!val && (inp.name === 'name' || inp.name === 'email')) {
          inp.style.borderColor = '#dc2626';
          hasRequired = false;
        } else {
          inp.style.borderColor = '';
        }
      });

      if (!hasRequired) return;

      submitBtn.disabled = true;
      submitBtn.textContent = 'Submitting...';

      var siteUrl = config.endpoints.stream.replace('/ai-agent/chat/stream', '');
      fetch(siteUrl + '/ai-agent/escalate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ sessionId: sessionId, contact: contactData }),
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          formWrapper.remove();
          var confirmation = (data && data.confirmation) || esc.confirmation || 'Thank you! We will be in touch.';
          addBotMessage(confirmation, true);
        })
        .catch(function () {
          submitBtn.disabled = false;
          submitBtn.textContent = 'Submit';
          addBotMessage('Sorry, there was an error submitting the form. Please try again.');
        });
    });
  }

  function scrollToBottom() {
    requestAnimationFrame(function () {
      messagesEl.scrollTop = messagesEl.scrollHeight;
    });
  }

  function renderMarkdown(text) {
    if (!text) return '';
    text = escapeHtml(text);
    // Bold
    text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    // Italic
    text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');
    // Inline code
    text = text.replace(/`(.*?)`/g, '<code>$1</code>');
    // Links
    text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
    // Line breaks
    text = text.replace(/\n/g, '<br>');
    return text;
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }

  function matchGlob(pattern, path) {
    var regex = pattern
      .replace(/[.+^${}()|[\]\\]/g, '\\$&')
      .replace(/\*\*/g, '{{GLOBSTAR}}')
      .replace(/\*/g, '[^/]*')
      .replace(/\{\{GLOBSTAR\}\}/g, '.*');
    return new RegExp('^' + regex + '$').test(path);
  }

  function getSessionId() {
    var key = 'ai_agent_session';
    var id = localStorage.getItem(key);
    if (!id) {
      id = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
        var r = Math.random() * 16 | 0;
        return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
      });
      localStorage.setItem(key, id);
    }
    return id;
  }

  function loadMessages() {
    try {
      var stored = localStorage.getItem('ai_agent_messages_' + sessionId);
      return stored ? JSON.parse(stored) : [];
    } catch (e) {
      return [];
    }
  }

  function saveMessages() {
    try {
      var toSave = messages.slice(-50);
      localStorage.setItem('ai_agent_messages_' + sessionId, JSON.stringify(toSave));
    } catch (e) { /* quota exceeded */ }
  }

  function getStyles() {
    var t = config.theme || {};
    var primary = t.primaryColor || '#2563eb';
    var secondary = t.secondaryColor || '#f3f4f6';
    var bg = t.backgroundColor || '#ffffff';
    var text = t.textColor || '#1f2937';
    var font = t.fontFamily || '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
    var pos = config.position || 'bottom-right';
    var posRight = pos === 'bottom-right' ? '20px' : 'auto';
    var posLeft = pos === 'bottom-left' ? '20px' : 'auto';

    return ':host { all: initial; display: block; font-family: ' + font + '; font-size: 14px; line-height: 1.5; color: ' + text + '; }' +
      '.ai-widget { position: fixed; bottom: 20px; right: ' + posRight + '; left: ' + posLeft + '; z-index: 999999; font-family: inherit; }' +
      '.ai-toggle { width: 56px; height: 56px; border-radius: 50%; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #fff; background: ' + primary + '; box-shadow: 0 4px 14px rgba(0,0,0,0.2); transition: transform 0.2s, box-shadow 0.2s; position: absolute; bottom: 0; ' + (pos === 'bottom-right' ? 'right: 0' : 'left: 0') + '; }' +
      '.ai-toggle:hover { transform: scale(1.08); box-shadow: 0 6px 20px rgba(0,0,0,0.25); }' +
      '.ai-panel { position: absolute; bottom: 0; ' + (pos === 'bottom-right' ? 'right: 0' : 'left: 0') + '; width: 380px; max-width: calc(100vw - 40px); height: 560px; max-height: calc(100vh - 100px); background: ' + bg + '; border-radius: 16px; box-shadow: 0 8px 32px rgba(0,0,0,0.15); display: none; flex-direction: column; overflow: hidden; }' +
      '.ai-panel.open { display: flex; animation: slideUp 0.3s ease; }' +
      '@keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }' +
      '.ai-header { background: ' + primary + '; color: #fff; padding: 16px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }' +
      '.ai-header-info { display: flex; align-items: center; gap: 10px; }' +
      '.ai-avatar { width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px; }' +
      '.ai-name { font-weight: 600; font-size: 15px; }' +
      '.ai-status { font-size: 12px; opacity: 0.8; }' +
      '.ai-close { background: none; border: none; color: #fff; font-size: 24px; cursor: pointer; padding: 0 4px; opacity: 0.8; line-height: 1; }' +
      '.ai-close:hover { opacity: 1; }' +
      '.ai-messages { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 8px; }' +
      '.ai-msg { display: flex; }' +
      '.ai-msg-user { justify-content: flex-end; }' +
      '.ai-msg-assistant { justify-content: flex-start; }' +
      '.ai-bubble { max-width: 85%; padding: 10px 14px; border-radius: 16px; word-wrap: break-word; overflow-wrap: break-word; }' +
      '.ai-msg-user .ai-bubble { background: ' + primary + '; color: #fff; border-bottom-right-radius: 4px; }' +
      '.ai-msg-assistant .ai-bubble { background: ' + secondary + '; color: ' + text + '; border-bottom-left-radius: 4px; }' +
      '.ai-bubble code { background: rgba(0,0,0,0.08); padding: 1px 4px; border-radius: 3px; font-size: 13px; }' +
      '.ai-bubble a { color: ' + primary + '; text-decoration: underline; }' +
      '.ai-msg-user .ai-bubble a { color: rgba(255,255,255,0.9); }' +
      '.ai-typing { display: flex; gap: 4px; padding: 14px 18px !important; }' +
      '.ai-typing span { width: 8px; height: 8px; border-radius: 50%; background: ' + text + '; opacity: 0.3; animation: typingDot 1.4s infinite ease-in-out; }' +
      '.ai-typing span:nth-child(2) { animation-delay: 0.2s; }' +
      '.ai-typing span:nth-child(3) { animation-delay: 0.4s; }' +
      '@keyframes typingDot { 0%, 80%, 100% { opacity: 0.3; transform: scale(1); } 40% { opacity: 1; transform: scale(1.2); } }' +
      '.ai-tool-indicator { font-size: 12px; color: #6b7280; font-style: italic; padding: 4px 0; }' +
      '.ai-input-area { display: flex; gap: 8px; padding: 12px 16px; border-top: 1px solid #e5e7eb; align-items: flex-end; flex-shrink: 0; background: ' + bg + '; }' +
      '.ai-input { flex: 1; border: 1px solid #d1d5db; border-radius: 20px; padding: 10px 16px; font-size: 14px; font-family: inherit; resize: none; outline: none; max-height: 120px; line-height: 1.4; color: ' + text + '; background: transparent; }' +
      '.ai-input:focus { border-color: ' + primary + '; box-shadow: 0 0 0 2px ' + primary + '33; }' +
      '.ai-input::placeholder { color: #9ca3af; }' +
      '.ai-send { width: 38px; height: 38px; border-radius: 50%; border: none; background: ' + primary + '; color: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: opacity 0.2s; }' +
      '.ai-send:hover { opacity: 0.9; }' +
      '.ai-escalation-form { width: 100%; max-width: 100%; }' +
      '.ai-esc-field { margin-bottom: 8px; }' +
      '.ai-esc-field label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 2px; color: ' + text + '; }' +
      '.ai-esc-input { width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; font-family: inherit; outline: none; box-sizing: border-box; background: ' + bg + '; color: ' + text + '; }' +
      '.ai-esc-input:focus { border-color: ' + primary + '; box-shadow: 0 0 0 2px ' + primary + '33; }' +
      '.ai-esc-submit { width: 100%; padding: 10px; border: none; border-radius: 8px; background: ' + primary + '; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; margin-top: 4px; font-family: inherit; }' +
      '.ai-esc-submit:hover { opacity: 0.9; }' +
      '.ai-esc-submit:disabled { opacity: 0.6; cursor: not-allowed; }' +
      '@media (max-width: 480px) { .ai-panel { width: calc(100vw - 20px); height: calc(100vh - 80px); border-radius: 16px 16px 0 0; bottom: 0; right: 0; left: 0; } .ai-widget { right: 10px; left: 10px; bottom: 10px; } }';
  }

})();
