((window, document) =>
{
	'use strict'

	const toInt = (value, fallback = 0) =>
	{
		const n = parseInt(value, 10)
		return Number.isFinite(n) ? n : fallback
	}

	// Plays every animation row of a spritesheet on a canvas placed next to the source <img>.
	// Without a stored frame size the frames are derived from the image: the sheet is
	// assumed to be `cols` frames wide and `rows` animations tall.
	XF.SylSpritePreview = XF.Element.newHandler({
		options: {
			frameW: 0,
			frameH: 0,
			cols: 4,
			rows: 4,
			fps: 4,
			pixelated: true,
		},

		image: null,
		canvas: null,
		ctx: null,
		sheet: null,
		frameW: 0,
		frameH: 0,
		cols: 4,
		rows: 4,
		msPerFrame: 250,
		col: 0,
		row: 0,
		elapsed: 0,
		lastTs: 0,
		raf: 0,
		boundTick: null,

		init ()
		{
			if (!(this.target instanceof HTMLImageElement))
			{
				console.error('sprite-preview: target must be an <img> element')
				return
			}

			this.image = this.target
			this.cols = Math.max(1, toInt(this.options.cols, 4))
			this.rows = Math.max(1, toInt(this.options.rows, 4))
			this.msPerFrame = Math.floor(1000 / Math.min(120, Math.max(1, toInt(this.options.fps, 4))))

			this.sheet = new Image()
			XF.on(this.sheet, 'load', this.onSheetLoad.bind(this))
			XF.on(this.sheet, 'error', () =>
			{
				console.error('sprite-preview: could not load', this.image.currentSrc || this.image.src)
			})
			this.sheet.src = this.image.currentSrc || this.image.src
		},

		onSheetLoad ()
		{
			const sheetW = this.sheet.naturalWidth
			const sheetH = this.sheet.naturalHeight
			if (!sheetW || !sheetH)
			{
				return
			}

			this.frameW = toInt(this.options.frameW) || Math.floor(sheetW / this.cols)
			this.frameH = toInt(this.options.frameH) || Math.floor(sheetH / this.rows)
			if (this.frameW < 1 || this.frameH < 1)
			{
				return
			}

			this.cols = Math.max(1, Math.min(this.cols, Math.floor(sheetW / this.frameW)))
			this.rows = Math.max(1, Math.min(this.rows, Math.floor(sheetH / this.frameH)))

			this.canvas = document.createElement('canvas')
			this.canvas.className = 'spritePreview-canvas'
			this.canvas.width = this.frameW
			this.canvas.height = this.frameH
			this.canvas.setAttribute('role', 'img')
			if (this.image.alt)
			{
				this.canvas.setAttribute('aria-label', this.image.alt)
			}

			this.ctx = this.canvas.getContext('2d')
			if (!this.ctx)
			{
				return
			}
			this.ctx.imageSmoothingEnabled = !this.options.pixelated
			this.canvas.classList.toggle('spritePreview-canvas--pixelated', !!this.options.pixelated)

			this.image.after(this.canvas)
			this.image.hidden = true

			this.render()
			this.boundTick = this.tick.bind(this)
			this.raf = requestAnimationFrame(this.boundTick)
		},

		tick (ts)
		{
			if (!this.canvas || !this.canvas.isConnected)
			{
				this.raf = 0
				return
			}

			if (!this.lastTs)
			{
				this.lastTs = ts
			}
			this.elapsed += ts - this.lastTs
			this.lastTs = ts

			let advanced = false
			while (this.elapsed >= this.msPerFrame)
			{
				this.elapsed -= this.msPerFrame
				this.advanceFrame()
				advanced = true
			}

			if (advanced)
			{
				this.render()
			}

			this.raf = requestAnimationFrame(this.boundTick)
		},

		advanceFrame ()
		{
			this.col++
			if (this.col >= this.cols)
			{
				this.col = 0
				this.row = (this.row + 1) % this.rows
			}
		},

		render ()
		{
			const fw = this.frameW
			const fh = this.frameH

			this.ctx.clearRect(0, 0, fw, fh)
			this.ctx.drawImage(this.sheet, this.col * fw, this.row * fh, fw, fh, 0, 0, fw, fh)
		},
	})

	XF.Element.register('syl-sprite-preview', 'XF.SylSpritePreview')
})(window, document)
