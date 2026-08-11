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
  var avatarContent = config.avatarUrl
    ? '<img src="' + escapeHtml(config.avatarUrl) + '" alt="" class="ai-avatar-img">'
    : config.agentName.charAt(0).toUpperCase();

  var header = document.createElement('div');
  header.className = 'ai-header';
  header.innerHTML = '<div class="ai-header-info"><div class="ai-avatar">' +
    avatarContent +
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

    if (role === 'assistant') {
      wrapper.appendChild(createMsgAvatar());
    }

    var bubble = document.createElement('div');
    bubble.className = 'ai-bubble';
    bubble.innerHTML = renderMarkdown(content);

    wrapper.appendChild(bubble);
    messagesEl.appendChild(wrapper);
  }

  function createMsgAvatar() {
    var el = document.createElement('div');
    el.className = 'ai-msg-avatar';
    if (config.avatarUrl) {
      el.innerHTML = '<img src="' + escapeHtml(config.avatarUrl) + '" alt="" class="ai-avatar-img">';
    } else {
      el.textContent = config.agentName.charAt(0).toUpperCase();
    }
    return el;
  }

  function streamResponse(text) {
    isStreaming = true;

    // Show typing indicator
    var typingEl = document.createElement('div');
    typingEl.className = 'ai-msg ai-msg-assistant';
    typingEl.appendChild(createMsgAvatar());
    var typingBubble = document.createElement('div');
    typingBubble.className = 'ai-bubble ai-typing';
    typingBubble.innerHTML = '<span></span><span></span><span></span>';
    typingEl.appendChild(typingBubble);
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
        wrapper.appendChild(createMsgAvatar());
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
    var fields = esc.fields || [];

    var formWrapper = document.createElement('div');
    formWrapper.className = 'ai-msg ai-msg-assistant';
    formWrapper.appendChild(createMsgAvatar());

    var formBubble = document.createElement('div');
    formBubble.className = 'ai-bubble ai-escalation-form';

    var html = '<div style="font-weight:600;margin-bottom:8px;">Contact Information</div>';

    for (var i = 0; i < fields.length; i++) {
      var f = fields[i];
      var handle = escapeHtml(f.handle || 'field_' + i);
      var label = escapeHtml(f.label || handle);
      var ph = escapeHtml(f.placeholder || '');
      var req = f.required ? ' data-required="1"' : '';

      html += '<div class="ai-esc-field"><label>' + label + (f.required ? ' <span style="color:#dc2626">*</span>' : '') + '</label>';

      if (f.type === 'textarea') {
        html += '<textarea name="' + handle + '" placeholder="' + ph + '" class="ai-esc-input" rows="3"' + req + '></textarea>';
      } else if (f.type === 'select' && f.options && f.options.length) {
        html += '<select name="' + handle + '" class="ai-esc-input"' + req + '><option value="">— Select —</option>';
        for (var o = 0; o < f.options.length; o++) {
          html += '<option value="' + escapeHtml(f.options[o]) + '">' + escapeHtml(f.options[o]) + '</option>';
        }
        html += '</select>';
      } else if (f.type === 'checkbox' && f.options && f.options.length) {
        html += '<div class="ai-esc-checkboxes" data-name="' + handle + '"' + req + '>';
        for (var c = 0; c < f.options.length; c++) {
          html += '<label class="ai-esc-checkbox-label"><input type="checkbox" value="' + escapeHtml(f.options[c]) + '"> ' + escapeHtml(f.options[c]) + '</label>';
        }
        html += '</div>';
      } else {
        var inputType = (f.type === 'email' || f.type === 'tel') ? f.type : 'text';
        html += '<input type="' + inputType + '" name="' + handle + '" placeholder="' + ph + '" class="ai-esc-input"' + req + '>';
      }

      html += '</div>';
    }

    html += '<button type="button" class="ai-esc-submit">Submit</button>';
    formBubble.innerHTML = html;
    formWrapper.appendChild(formBubble);
    messagesEl.appendChild(formWrapper);
    scrollToBottom();

    var submitBtn = formBubble.querySelector('.ai-esc-submit');
    submitBtn.addEventListener('click', function () {
      var contactData = {};
      var hasRequired = true;

      // Collect text/email/tel/textarea/select inputs
      var inputs = formBubble.querySelectorAll('.ai-esc-input');
      inputs.forEach(function (inp) {
        if (inp.name) {
          var val = inp.value.trim();
          contactData[inp.name] = val;
          if (!val && inp.getAttribute('data-required') === '1') {
            inp.style.borderColor = '#dc2626';
            hasRequired = false;
          } else {
            inp.style.borderColor = '';
          }
        }
      });

      // Collect checkbox groups
      var checkboxGroups = formBubble.querySelectorAll('.ai-esc-checkboxes');
      checkboxGroups.forEach(function (group) {
        var name = group.getAttribute('data-name');
        var checked = [];
        group.querySelectorAll('input[type="checkbox"]:checked').forEach(function (cb) {
          checked.push(cb.value);
        });
        contactData[name] = checked;
        if (checked.length === 0 && group.getAttribute('data-required') === '1') {
          group.style.borderColor = '#dc2626';
          hasRequired = false;
        } else {
          group.style.borderColor = '';
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
    // Links: label/URL already escaped (no breakout); reject unsafe schemes.
    text = text.replace(
      /\[([^\]]+)\]\(([^)]+)\)/g,
      function (match, label, url) {
        var href = safeUrl(url);
        if (href === null) {
          return match; // not a safe URL -> leave [label](url) as text
        }
        return (
          '<a href="' +
          href +
          '" target="_blank" rel="noopener noreferrer">' +
          label +
          '</a>'
        );
      },
    );
    // Line breaks
    text = text.replace(/\n/g, '<br>');
    return text;
  }

  // Returns the (escaped) URL if it's http/https/mailto/relative, else null.
  function safeUrl(url) {
    var decoded = url
      .replace(/&lt;/g, '<')
      .replace(/&gt;/g, '>')
      .replace(/&quot;/g, '"')
      .replace(/&#39;/g, "'")
      .replace(/&amp;/g, '&');
    // Real URLs percent-encode these; raw = breakout attempt.
    if (/[\s"'<>`]/.test(decoded)) {
      return null;
    }
    var lower = decoded.toLowerCase();
    if (/^(https?:|mailto:)/.test(lower)) {
      return url;
    }
    if (/^[a-z][a-z0-9.+-]*:/.test(lower)) {
      return null; // other explicit scheme (javascript:, data:, ...) -> reject
    }
    return url; // no scheme = relative -> safe
  }

  function escapeHtml(str) {
    if (str == null) {
      return '';
    }
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
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
      '.ai-avatar { width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px; overflow: hidden; flex-shrink: 0; }' +
      '.ai-avatar-img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }' +
      '.ai-name { font-weight: 600; font-size: 15px; }' +
      '.ai-status { font-size: 12px; opacity: 0.8; }' +
      '.ai-close { background: none; border: none; color: #fff; font-size: 24px; cursor: pointer; padding: 0 4px; opacity: 0.8; line-height: 1; }' +
      '.ai-close:hover { opacity: 1; }' +
      '.ai-messages { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 8px; }' +
      '.ai-msg { display: flex; }' +
      '.ai-msg-user { justify-content: flex-end; }' +
      '.ai-msg-assistant { justify-content: flex-start; align-items: flex-end; }' +
      '.ai-msg-avatar { width: 24px; height: 24px; border-radius: 50%; background: ' + primary + '; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 11px; flex-shrink: 0; overflow: hidden; margin-right: 6px; }' +
      '.ai-msg-avatar .ai-avatar-img { width: 100%; height: 100%; object-fit: cover; }' +
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
      'select.ai-esc-input { appearance: auto; cursor: pointer; }' +
      'textarea.ai-esc-input { resize: vertical; min-height: 60px; }' +
      '.ai-esc-checkboxes { display: flex; flex-direction: column; gap: 4px; padding: 4px 0; }' +
      '.ai-esc-checkbox-label { display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; color: ' + text + '; }' +
      '.ai-esc-checkbox-label input { width: 16px; height: 16px; cursor: pointer; }' +
      '.ai-esc-submit { width: 100%; padding: 10px; border: none; border-radius: 8px; background: ' + primary + '; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; margin-top: 4px; font-family: inherit; }' +
      '.ai-esc-submit:hover { opacity: 0.9; }' +
      '.ai-esc-submit:disabled { opacity: 0.6; cursor: not-allowed; }' +
      '@media (max-width: 480px) { .ai-panel { width: calc(100vw - 20px); height: calc(100vh - 80px); border-radius: 16px 16px 0 0; bottom: 0; right: 0; left: 0; } .ai-widget { right: 10px; left: 10px; bottom: 10px; } }';
  }

})();
