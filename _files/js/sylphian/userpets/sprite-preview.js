(function() {
    'use strict';

    const clampInt = (v, min, max) => {
        v = parseInt(v);
        if (!Number.isFinite(v)) v = min ?? 0;
        if (min != null) v = Math.max(min, v);
        if (max != null) v = Math.min(max, v);
        return v;
    };
    const toBool = v => {
        if (typeof v === 'boolean') return v;
        if (v == null) return false;
        const s = String(v).toLowerCase();
        return s === '1' || s === 'true' || s === 'yes' || s === 'on';
    };

    XF.SylSpritePreview = XF.Element.newHandler({
        options: {
            src: null,
            frameW: 32,
            frameH: 32,
            cols: 4,
            rows: 1,
            fps: 8,
            scale: 1,
            pixelated: true
        },

        _imgEl: null,
        _canvas: null,
        _ctx: null,
        _sheet: null,
        _loaded: false,
        _raf: 0,
        _accum: 0,
        _msPerFrame: 125,
        _col: 0,
        _row: 0,
        _lastTs: 0,
        _dpr: (window.devicePixelRatio || 1),

        init: function() {
            this._imgEl = this.target;
            if (!(this._imgEl instanceof HTMLImageElement)) {
                console.error('sprite-preview: target must be <img>');
                return;
            }

            const el = this._imgEl;
            const ds = el.dataset || {};

            this.options.src    = ds.src || el.getAttribute('src') || this.options.src;
            this.options.frameW = clampInt(ds.frameW ?? el.getAttribute('data-frame-w'), 1);
            this.options.frameH = clampInt(ds.frameH ?? el.getAttribute('data-frame-h'), 1);
            this.options.cols   = clampInt(ds.cols   ?? el.getAttribute('data-cols'),   1);
            this.options.rows   = clampInt(ds.rows   ?? el.getAttribute('data-rows'),   1);
            this.options.fps    = clampInt(ds.fps    ?? el.getAttribute('data-fps'),    1, 120);

            const scaleAttr = ds.scale ?? el.getAttribute('data-scale');
            const scale = parseFloat(scaleAttr);
            if (Number.isFinite(scale) && scale > 0) this.options.scale = scale;

            const pixAttr = ds.pixelated ?? el.getAttribute('data-pixelated');
            if (pixAttr != null) this.options.pixelated = toBool(pixAttr);

            if (console && console.debug) {
                console.debug('syl-sprite-preview init', {
                    src: this.options.src,
                    w: this.options.frameW,
                    h: this.options.frameH,
                    cols: this.options.cols,
                    rows: this.options.rows,
                    fps: this.options.fps
                });
            }

            this._canvas = document.createElement('canvas');
            this._ctx = this._canvas.getContext('2d');
            if (!this._ctx) return;

            el.style.display = 'none';
            el.parentNode.insertBefore(this._canvas, el.nextSibling);

            this._ctx.imageSmoothingEnabled = !this.options.pixelated;
            if (this.options.pixelated) this._canvas.style.imageRendering = 'pixelated';

            this._canvas.style.border = '2px solid hsla(var(--xf-sylUserPetsCanvasBorder)';
            this._canvas.style.borderRadius = '4px';

            this._msPerFrame = Math.max(1, Math.floor(1000 / this.options.fps));

            const cssW = this.options.frameW * this.options.scale;
            const cssH = this.options.frameH * this.options.scale;
            const dpr = this._dpr;
            this._canvas.width  = Math.max(1, Math.round(this.options.frameW * dpr));
            this._canvas.height = Math.max(1, Math.round(this.options.frameH * dpr));
            this._canvas.style.width  = cssW + 'px';
            this._canvas.style.height = cssH + 'px';


            this._sheet = new Image();
            this._sheet.onload = XF.proxy(function() {
                this._loaded = true;
                this._start();
            }, this);
            this._sheet.onerror = function(e) {
                console.error('sprite-preview: failed to load image', e);
            };
            this._sheet.src = this.options.src;
        },

        _start: function() {
            this._accum = 0;
            this._col = 0;
            this._row = 0;
            this._lastTs = 0;
            this._tick = this._tick.bind(this);
            this._raf = requestAnimationFrame(this._tick);
        },

        _tick: function(ts) {
            if (!this._lastTs) this._lastTs = ts;
            const dt = ts - this._lastTs;
            this._lastTs = ts;
            this._accum += dt;

            while (this._accum >= this._msPerFrame) {
                this._accum -= this._msPerFrame;
                this._advanceFrame();
            }

            this._render();
            this._raf = requestAnimationFrame(this._tick);
        },

        _advanceFrame: function() {
            this._col++;
            if (this._col >= this.options.cols) {
                this._col = 0;
                this._row++;
                if (this._row >= this.options.rows) this._row = 0;
            }
        },

        _render: function() {
            if (!this._loaded) return;
            const dpr = this._dpr;
            const fw = this.options.frameW;
            const fh = this.options.frameH;
            const sx = this._col * fw;
            const sy = this._row * fh;

            this._ctx.clearRect(0, 0, this._canvas.width, this._canvas.height);
            this._ctx.drawImage(this._sheet, sx, sy, fw, fh, 0, 0, Math.round(fw * dpr), Math.round(fh * dpr));
        },

        _destroy: function() {
            if (this._raf) cancelAnimationFrame(this._raf);
            this._raf = 0;
            this._loaded = false;
            if (this._canvas && this._canvas.parentNode && this._imgEl) {
                this._imgEl.style.display = '';
                this._canvas.parentNode.removeChild(this._canvas);
            }
            this._canvas = null;
            this._ctx = null;
            this._sheet = null;
        }
    });

    XF.Element.register('syl-sprite-preview', XF.SylSpritePreview);
})();