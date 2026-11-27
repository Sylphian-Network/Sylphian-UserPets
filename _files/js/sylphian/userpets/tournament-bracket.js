((window, document) =>
{
	'use strict'

	// Zoom (buttons, Ctrl + wheel, trackpad pinch) and mouse drag panning for the tournament bracket.
	// Plain wheel and touch input keep scrolling natively so the page never gets stuck on the bracket.
	XF.UserPetsBracket = XF.Element.newHandler({
		options: {
			minScale: 0.3,
			maxScale: 1.5,
			step: 0.1,
		},

		viewport: null,
		sizer: null,
		canvas: null,
		valueEl: null,
		scale: 1,
		drag: null,
		suppressClick: false,

		init ()
		{
			this.viewport = this.target.querySelector('.js-bracketViewport')
			this.sizer = this.target.querySelector('.js-bracketSizer')
			this.canvas = this.target.querySelector('.js-bracketCanvas')
			this.valueEl = this.target.querySelector('.js-bracketZoomValue')

			if (!this.viewport || !this.sizer || !this.canvas)
			{
				return
			}

			this.bindButton('.js-bracketZoomIn', () => this.zoomBy(this.options.step))
			this.bindButton('.js-bracketZoomOut', () => this.zoomBy(-this.options.step))
			this.bindButton('.js-bracketZoomReset', () => this.setScale(1))

			XF.on(this.viewport, 'wheel', this.onWheel.bind(this), { passive: false })
			XF.on(this.viewport, 'pointerdown', this.onPointerDown.bind(this))
			XF.on(this.viewport, 'pointermove', this.onPointerMove.bind(this))
			XF.on(this.viewport, 'pointerup', this.onPointerUp.bind(this))
			XF.on(this.viewport, 'pointercancel', this.onPointerUp.bind(this))
			XF.on(this.viewport, 'click', this.onClickCapture.bind(this), { capture: true })

			this.setScale(1)
		},

		bindButton (selector, callback)
		{
			const button = this.target.querySelector(selector)
			if (button)
			{
				XF.on(button, 'click', e =>
				{
					e.preventDefault()
					callback()
				})
			}
		},

		zoomBy (delta, originX, originY)
		{
			this.setScale(this.scale + delta, originX, originY)
		},

		setScale (nextScale, originX, originY)
		{
			const { minScale, maxScale } = this.options
			const oldScale = this.scale
			const scale = Math.round(Math.min(maxScale, Math.max(minScale, nextScale)) * 100) / 100

			if (originX === undefined)
			{
				originX = this.viewport.clientWidth / 2
				originY = this.viewport.clientHeight / 2
			}

			const focusX = (this.viewport.scrollLeft + originX) / oldScale
			const focusY = (this.viewport.scrollTop + originY) / oldScale

			this.scale = scale

			// Transforms do not change layout size, so the sizer carries the scaled
			// dimensions to give the viewport correct scroll extents.
			const width = this.canvas.offsetWidth
			const height = this.canvas.offsetHeight
			this.canvas.style.transform = scale === 1 ? '' : `scale(${scale})`
			this.sizer.style.width = `${Math.ceil(width * scale)}px`
			this.sizer.style.height = `${Math.ceil(height * scale)}px`

			this.viewport.scrollLeft = (focusX * scale) - originX
			this.viewport.scrollTop = (focusY * scale) - originY

			if (this.valueEl)
			{
				this.valueEl.textContent = XF.phrase('sylphian_userpets_zoom_x_percent', {
					'{percent}': Math.round(scale * 100),
				})
			}
		},

		onWheel (e)
		{
			if (!e.ctrlKey && !e.metaKey)
			{
				return
			}

			e.preventDefault()

			const rect = this.viewport.getBoundingClientRect()
			this.zoomBy(
				e.deltaY < 0 ? this.options.step : -this.options.step,
				e.clientX - rect.left,
				e.clientY - rect.top
			)
		},

		onPointerDown (e)
		{
			if (e.pointerType !== 'mouse' || e.button !== 0)
			{
				return
			}

			this.drag = {
				pointerId: e.pointerId,
				x: e.clientX,
				y: e.clientY,
				left: this.viewport.scrollLeft,
				top: this.viewport.scrollTop,
				moved: false,
			}
		},

		onPointerMove (e)
		{
			const drag = this.drag
			if (!drag || e.pointerId !== drag.pointerId)
			{
				return
			}

			const dx = e.clientX - drag.x
			const dy = e.clientY - drag.y

			if (!drag.moved)
			{
				if (Math.abs(dx) < 4 && Math.abs(dy) < 4)
				{
					return
				}

				drag.moved = true
				this.viewport.setPointerCapture(e.pointerId)
				this.viewport.classList.add('is-dragging')
			}

			e.preventDefault()
			this.viewport.scrollLeft = drag.left - dx
			this.viewport.scrollTop = drag.top - dy
		},

		onPointerUp (e)
		{
			const drag = this.drag
			if (!drag || e.pointerId !== drag.pointerId)
			{
				return
			}

			if (drag.moved)
			{
				this.suppressClick = true
				// The click (if any) is dispatched right after pointerup; never let the flag leak to a later click.
				setTimeout(() =>
				{
					this.suppressClick = false
				}, 0)

				this.viewport.classList.remove('is-dragging')
				if (this.viewport.hasPointerCapture(e.pointerId))
				{
					this.viewport.releasePointerCapture(e.pointerId)
				}
			}

			this.drag = null
		},

		// A drag that ends on a username must not follow the link.
		onClickCapture (e)
		{
			if (this.suppressClick)
			{
				this.suppressClick = false
				e.preventDefault()
				e.stopPropagation()
			}
		},
	})

	XF.Element.register('userpets-bracket', 'XF.UserPetsBracket')
})(window, document)
