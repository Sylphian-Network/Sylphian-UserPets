((window, document) =>
{
	'use strict'

	const STATE_ROWS = {
		idle: 0,
		feed: 1,
		play: 2,
		sleep: 3,
	}

	const CANVAS_SIZE = 192
	const MAX_LEVEL = 100
	const ACTION_ANIMATION_MS = 3000

	XF.PetRenderer = XF.Element.newHandler({
		options: {
			spriteSheetPath: '',
			petState: 'idle',
			level: 1,
			frameWidth: 192,
			frameHeight: 192,
			fps: 4,
			framesPerAnimation: 4,
			scaleMin: 0.5,
			scaleMax: 1.0,
		},

		canvas: null,
		context: null,
		container: null,
		spriteSheet: null,
		spriteSheetLoaded: false,
		baseState: 'idle',
		currentState: 'idle',
		currentFrame: 0,
		animationInterval: null,
		resetTimer: null,
		targetScale: 0.5,
		currentScale: 0.5,

		init ()
		{
			if (!(this.target instanceof HTMLCanvasElement))
			{
				console.error('PetRenderer: target must be a canvas element')
				return
			}

			this.canvas = this.target
			this.context = this.canvas.getContext('2d')
			if (!this.context)
			{
				return
			}

			this.canvas.width = CANVAS_SIZE
			this.canvas.height = CANVAS_SIZE

			this.normalizeOptions()

			const state = String(this.options.petState || '').toLowerCase()
			this.baseState = XF.hasOwn(STATE_ROWS, state) ? state : 'idle'
			this.currentState = this.baseState

			this.targetScale = this.calculateScale(this.options.level)
			this.currentScale = this.targetScale

			this.container = this.canvas.closest('.js-petWidget')
			if (this.container)
			{
				XF.on(this.container, 'userpets:action', this.onAction.bind(this))
				XF.on(this.container, 'userpets:level-change', this.onLevelChange.bind(this))
			}

			this.loadSpriteSheet()
			this.startAnimation()
		},

		normalizeOptions ()
		{
			const clamp = (value, fallback, min, max) =>
			{
				let n = Number(value)
				if (!Number.isFinite(n))
				{
					n = fallback
				}
				if (min !== undefined)
				{
					n = Math.max(min, n)
				}
				if (max !== undefined)
				{
					n = Math.min(max, n)
				}
				return n
			}

			const o = this.options
			o.frameWidth = clamp(o.frameWidth, 192, 1)
			o.frameHeight = clamp(o.frameHeight, 192, 1)
			o.fps = clamp(o.fps, 4, 1, 120)
			o.framesPerAnimation = Math.round(clamp(o.framesPerAnimation, 4, 1))
			o.scaleMin = clamp(o.scaleMin, 0.5, 0.01)
			o.scaleMax = clamp(o.scaleMax, 1.0, 0.01)
			o.level = Math.round(clamp(o.level, 1, 1))

			if (o.scaleMin > o.scaleMax)
			{
				[o.scaleMin, o.scaleMax] = [o.scaleMax, o.scaleMin]
			}
		},

		// Power 1.5 curve so the pet grows quickly early on and slowly at high levels.
		calculateScale (level)
		{
			level = Math.max(1, parseInt(level, 10) || 1)

			const { scaleMin, scaleMax } = this.options
			const progress = Math.pow(Math.min(level, MAX_LEVEL) / MAX_LEVEL, 1.5)

			return Math.min(scaleMax, Math.max(scaleMin, scaleMin + progress * (scaleMax - scaleMin)))
		},

		loadSpriteSheet ()
		{
			if (!this.options.spriteSheetPath)
			{
				return
			}

			this.spriteSheetLoaded = false
			this.spriteSheet = new Image()

			XF.on(this.spriteSheet, 'load', () =>
			{
				this.spriteSheetLoaded = true
				this.render()
			})
			XF.on(this.spriteSheet, 'error', () =>
			{
				console.error('PetRenderer: failed to load the sprite sheet', this.options.spriteSheetPath)
			})

			this.spriteSheet.src = this.options.spriteSheetPath
		},

		startAnimation ()
		{
			if (this.animationInterval)
			{
				clearInterval(this.animationInterval)
			}

			this.animationInterval = setInterval(() => this.update(), Math.floor(1000 / this.options.fps))
		},

		update ()
		{
			if (!this.canvas.isConnected)
			{
				clearInterval(this.animationInterval)
				this.animationInterval = null
				return
			}

			this.currentFrame = (this.currentFrame + 1) % this.options.framesPerAnimation

			if (this.currentScale !== this.targetScale)
			{
				const diff = this.targetScale - this.currentScale
				this.currentScale = Math.abs(diff) < 0.01
					? this.targetScale
					: this.currentScale + diff * 0.1
			}

			this.render()
		},

		render ()
		{
			if (!this.spriteSheetLoaded)
			{
				return
			}

			const ctx = this.context
			const canvas = this.canvas
			const fw = this.options.frameWidth
			const fh = this.options.frameHeight

			ctx.clearRect(0, 0, canvas.width, canvas.height)

			const sx = this.currentFrame * fw
			const sy = (STATE_ROWS[this.currentState] || 0) * fh

			// The drawn box is 128px at scale 0.5 and 192px at scale 1.0, whatever the frame size.
			const s = Math.min(1.0, Math.max(0.5, this.currentScale))
			const box = 128 + ((s - 0.5) / 0.5) * (CANVAS_SIZE - 128)

			let drawW, drawH
			if (fw >= fh)
			{
				drawW = box
				drawH = box * (fh / fw)
			}
			else
			{
				drawH = box
				drawW = box * (fw / fh)
			}

			ctx.drawImage(
				this.spriteSheet,
				sx, sy, fw, fh,
				(canvas.width - drawW) / 2, canvas.height - drawH, drawW, drawH
			)
		},

		changeState (state)
		{
			if (!XF.hasOwn(STATE_ROWS, state))
			{
				return
			}

			this.currentState = state
			this.currentFrame = 0
		},

		onAction (e)
		{
			if (!e.action || !XF.hasOwn(STATE_ROWS, e.action))
			{
				return
			}

			this.changeState(e.action)

			if (this.resetTimer)
			{
				clearTimeout(this.resetTimer)
			}
			this.resetTimer = setTimeout(() =>
			{
				this.resetTimer = null
				this.changeState(this.baseState)
			}, ACTION_ANIMATION_MS)
		},

		onLevelChange (e)
		{
			const level = parseInt(e.level, 10)
			if (!level)
			{
				return
			}

			this.canvas.dataset.level = String(level)
			this.targetScale = this.calculateScale(level)
		},
	})

	XF.Element.register('userpets-renderer', 'XF.PetRenderer')
})(window, document)
