(() => {
  'use strict';
  const configNode = document.getElementById('terminal-config');
  const config = configNode ? JSON.parse(configNode.textContent) : null;
  if (!config || !window.Terminal || !window.FitAddon) {
    document.body.innerHTML = '<p style="padding:24px;color:#f88">Terminal libraries failed to load. Check the CDN connection.</p>';
    return;
  }

  class PHPWebTerminal {
    constructor(cfg) {
      this.cfg = cfg;
      this.active = false;
      this.disconnected = false;
      this.offset = 0;
      this.pollTimer = null;
      this.polling = false;
      this.inputQueue = Promise.resolve();
      this.lastInputWasCR = false;
      this.fontSize = 14;
      this.terminal = new Terminal({
        cursorBlink: true,
        convertEol: false,
        scrollback: 5000,
        fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
        fontSize: this.fontSize,
        lineHeight: 1.25,
        allowProposedApi: false,
        theme: { background:'#10141b', foreground:'#dce5f2', cursor:'#e8b04a', cursorAccent:'#10141b', selectionBackground:'#e8b04a40', black:'#1d222d', red:'#ef6b73', green:'#8fcb7a', yellow:'#e8b04a', blue:'#6cb6ff', magenta:'#c9a0ff', cyan:'#5fd1c8', white:'#d8dce4', brightBlack:'#5b6478', brightRed:'#ff8b92', brightGreen:'#a9e394', brightYellow:'#f5c76a', brightBlue:'#92cbff', brightMagenta:'#dcbcff', brightCyan:'#7fe6dd', brightWhite:'#fff' }
      });
      this.fit = new FitAddon.FitAddon();
      this.terminal.loadAddon(this.fit);
      this.terminal.open(document.getElementById('terminal'));
      this.bindEvents();
      document.getElementById('host-label').textContent = `${cfg.user}@${cfg.host}:${this.displayCwd(cfg.cwd)}`;
      document.getElementById('cwd-label').textContent = cfg.cwd;
      this.terminal.write('\x1b[1;33mInteractive PHP Web Terminal\x1b[0m\r\n');
      window.requestAnimationFrame(() => { this.fit.fit(); this.connect(); });
    }

    displayCwd(path) {
      return this.cfg.home && path.startsWith(this.cfg.home) ? '~' + path.slice(this.cfg.home.length) : path;
    }

    bindEvents() {
      const byId = id => document.getElementById(id);
      this.terminal.onData(data => {
        if (!this.active || this.disconnected) return;
        this.sendInput(this.normalizeInput(data));
      });
      this.terminal.onResize(({cols, rows}) => {
        if (this.active && !this.disconnected) this.api('resize', {cols, rows}).catch(() => {});
      });
      window.addEventListener('resize', () => window.requestAnimationFrame(() => { try { this.fit.fit(); } catch (_) {} }));
      if ('ResizeObserver' in window) new ResizeObserver(() => window.requestAnimationFrame(() => { try { this.fit.fit(); } catch (_) {} })).observe(byId('terminal'));
      byId('btn-disconnect').addEventListener('click', () => this.disconnectView());
      byId('btn-connect').addEventListener('click', () => this.reconnect());
      byId('overlay-reconnect').addEventListener('click', () => this.reconnect());
      byId('btn-upload').addEventListener('click', () => byId('file-upload').click());
      byId('file-upload').addEventListener('change', event => {
        const file = event.target.files && event.target.files[0];
        if (file) this.uploadFile(file);
        event.target.value = '';
      });
      byId('btn-clear').addEventListener('click', () => { this.terminal.clear(); this.terminal.focus(); });
      byId('btn-larger').addEventListener('click', () => this.setFontSize(this.fontSize + 1));
      byId('btn-smaller').addEventListener('click', () => this.setFontSize(this.fontSize - 1));
      byId('btn-logout').addEventListener('click', () => this.logout());
      this.terminal.attachCustomKeyEventHandler(e => {
        if (e.type === 'keydown' && e.ctrlKey && e.shiftKey && e.key.toLowerCase() === 'c') {
          if (this.terminal.hasSelection() && navigator.clipboard) navigator.clipboard.writeText(this.terminal.getSelection()).catch(() => {});
          return false;
        }
        return true;
      });
    }

    // xterm emits Enter as CR. Only remove an LF that is paired with a CR,
    // including when the pair straddles two onData events. Never synthesize prompts.
    normalizeInput(data) {
      let out = '';
      for (const ch of data) {
        if (ch === '\n' && this.lastInputWasCR) { this.lastInputWasCR = false; continue; }
        out += ch;
        this.lastInputWasCR = ch === '\r';
      }
      return out;
    }

    async api(action, values = {}) {
      const body = new URLSearchParams({action, token:this.cfg.csrf, ...values});
      const response = await fetch(location.pathname, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-CSRF-Token':this.cfg.csrf}, body, cache:'no-store'});
      const result = await response.json();
      if (!response.ok || result.error) throw new Error(result.error || `Request failed (${response.status})`);
      return result;
    }

    async uploadFile(file) {
      if (!this.active || this.disconnected) {
        this.writeError('Reconnect the terminal before uploading a file.');
        return;
      }
      if (file.size > 20 * 1024 * 1024) {
        this.writeError('Files must be 20 MB or smaller.');
        return;
      }
      const form = new FormData();
      form.append('action', 'upload');
      form.append('token', this.cfg.csrf);
      form.append('file', file, file.name);
      try {
        const response = await fetch(location.pathname, {
          method: 'POST', credentials: 'same-origin',
          headers: {'X-CSRF-Token': this.cfg.csrf},
          body: form, cache: 'no-store'
        });
        const result = await response.json();
        if (!response.ok || result.error) throw new Error(result.error || `Upload failed (${response.status})`);
        this.terminal.write(`\r\n\x1b[32mUploaded ${result.name} (${result.size} bytes) to ${result.directory}\x1b[0m\r\n`);
        this.terminal.focus();
      } catch (error) {
        this.writeError(`Upload failed: ${error.message}`);
        this.terminal.focus();
      }
    }

    async connect() {
      this.setState('connecting');
      try {
        const result = await this.api('start', {cols:this.terminal.cols, rows:this.terminal.rows});
        this.offset = result.offset || 0;
        this.lastInputWasCR = false;
        this.active = true;
        this.disconnected = false;
        this.showOverlay(false);
        this.setState('connected');
        this.pollTimer = window.setInterval(() => this.poll(), 60);
        await this.poll();
        this.terminal.focus();
      } catch (error) {
        this.active = false;
        this.setState('disconnected');
        this.showOverlay(true);
        this.writeError(`Could not connect: ${error.message}`);
      }
    }

    sendInput(data) {
      if (!data) return;
      this.inputQueue = this.inputQueue.then(async () => {
        if (!this.active || this.disconnected) return;
        try { await this.api('input', {data}); }
        catch (error) { this.writeError(error.message); }
      }).catch(() => {});
    }

    async poll() {
      if (!this.active || this.disconnected || this.polling) return;
      this.polling = true;
      try {
        const result = await this.api('read', {offset:this.offset});
        if (result.data) {
          const bytes = Uint8Array.from(atob(result.data), c => c.charCodeAt(0));
          // This is the only path that writes shell output. No prompt is generated here.
          if (bytes.length) this.terminal.write(bytes);
        }
        if (Number.isFinite(result.offset)) this.offset = result.offset;
        if (!result.alive) {
          this.active = false;
          this.stopPolling();
          this.setState('disconnected');
          this.showOverlay(true);
        }
      } catch (_) {
        // A transient HTTP polling failure must not create terminal text or prompts.
      } finally { this.polling = false; }
    }

    disconnectView() {
      if (!this.active) { this.reconnect(); return; }
      this.disconnected = true;
      this.stopPolling();
      this.setState('disconnected');
      this.showOverlay(true);
    }

    async reconnect() {
      if (this.active) {
        this.disconnected = false;
        this.setState('connected');
        this.showOverlay(false);
        this.pollTimer = window.setInterval(() => this.poll(), 60);
        await this.poll();
        this.terminal.focus();
        return;
      }
      // A dead shell is a new shell; keep the terminal transcript visible.
      this.terminal.write('\r\n\x1b[2m--- starting a new shell ---\x1b[0m\r\n');
      await this.connect();
    }

    async logout() {
      try { await this.api('logout'); } catch (_) {}
      location.reload();
    }

    stopPolling() {
      if (this.pollTimer !== null) window.clearInterval(this.pollTimer);
      this.pollTimer = null;
    }

    setState(state) {
      const status = document.querySelector('.status');
      status.dataset.state = state;
      document.getElementById('status-label').textContent = state[0].toUpperCase() + state.slice(1);
      const dead = state === 'disconnected';
      document.getElementById('btn-disconnect').hidden = dead;
      document.getElementById('btn-connect').hidden = !dead;
    }

    showOverlay(show) { document.getElementById('terminal-overlay').hidden = !show; }
    setFontSize(size) { this.fontSize = Math.max(10, Math.min(24, size)); this.terminal.options.fontSize = this.fontSize; this.fit.fit(); this.terminal.focus(); }
    writeError(message) { this.terminal.write(`\r\n\x1b[1;31m${String(message).replace(/[\r\n]/g, ' ')}\x1b[0m\r\n`); }
  }

  // Keep the implementation private; no application class is added to window.
  new PHPWebTerminal(config);
})();
