((window, document) =>
{
	'use strict'

	XF.PetActions = XF.Element.newHandler({
		options: {
			actionUrl: '',
			cooldown: 0,
			lastActionTime: 0,
			currentTime: 0,
			soundEnabled: true,
			levelUpSound: 'data/assets/sylphian/userpets/audio/sparkle-355937.mp3',
		},

		buttons: [],
		cooldownEl: null,
		levelEl: null,
		expEl: null,
		cooldownTimer: null,
		cooldownEnd: 0,
		currentLevel: 1,
		busy: false,

		init ()
		{
			this.buttons = Array.from(this.target.querySelectorAll('.js-petAction'))
			this.cooldownEl = this.target.querySelector('.js-petCooldown')
			this.levelEl = this.target.querySelector('.js-petLevel')
			this.expEl = this.target.querySelector('.js-petExpNeeded')

			if (this.levelEl)
			{
				this.currentLevel = parseInt(this.levelEl.dataset.level, 10) || 1
			}

			this.buttons.forEach(button =>
			{
				XF.on(button, 'click', this.performAction.bind(this))
			})

			const { cooldown, lastActionTime, currentTime } = this.options
			if (lastActionTime && currentTime)
			{
				const elapsed = currentTime - lastActionTime
				if (elapsed < cooldown)
				{
					this.startCooldown(cooldown - elapsed)
				}
			}
		},

		performAction (e)
		{
			e.preventDefault()

			const button = e.currentTarget
			const action = button.dataset.action
			if (this.busy || !action || button.disabled)
			{
				return
			}

			this.busy = true
			this.setButtonsEnabled(false)

			XF.ajax('post', this.options.actionUrl, { action }, null, { skipDefault: true })
				.then(result => this.handleResponse(result, action))
				.catch(error =>
				{
					console.error(error)
					this.showError()
					this.setButtonsEnabled(true)
				})
				.finally(() =>
				{
					this.busy = false
				})
		},

		handleResponse ({ data, error }, action)
		{
			if (error || !data || typeof data !== 'object')
			{
				// XF.ajax has already shown its own error overlay for transport failures.
				this.setButtonsEnabled(true)
				return
			}

			if (data.status === 'error' || data.errors || data.errorHtml)
			{
				// Same overlay XF shows for any failed AJAX action.
				if (!XF.defaultAjaxSuccessError(data))
				{
					this.showError()
				}

				const remaining = parseInt(data.cooldownRemaining, 10)
				if (remaining > 0)
				{
					this.startCooldown(remaining)
				}
				else
				{
					this.setButtonsEnabled(true)
				}
				return
			}

			if (!data.success)
			{
				this.showError()
				this.setButtonsEnabled(true)
				return
			}

			this.updateStats(data)

			XF.trigger(this.target, XF.customEvent('userpets:action', { action }))

			if (data.message)
			{
				XF.flashMessage(data.message, 3000)
			}

			this.startCooldown(parseInt(data.cooldownTime, 10) || this.options.cooldown)
		},

		showError ()
		{
			XF.alert(XF.phrase('sylphian_userpets_action_error'))
		},

		updateStats (data)
		{
			this.target.querySelectorAll('.js-petStat').forEach(statEl =>
			{
				const stat = statEl.dataset.stat
				if (!stat || data[stat] === undefined)
				{
					return
				}

				const value = Math.max(0, Math.min(100, parseInt(data[stat], 10) || 0))

				const bar = statEl.querySelector('.js-petStatBar')
				if (bar)
				{
					bar.value = value
					bar.textContent = String(value)
				}

				const valueEl = statEl.querySelector('.js-petStatValue')
				if (valueEl)
				{
					valueEl.textContent = XF.phrase('sylphian_userpets_stat_x_of_max', {
						'{value}': value,
						'{max}': 100,
					})
				}
			})

			if (this.levelEl && data.level !== undefined)
			{
				const newLevel = parseInt(data.level, 10) || this.currentLevel

				this.levelEl.dataset.level = String(newLevel)
				if (data.levelText)
				{
					this.levelEl.textContent = data.levelText
				}

				if (newLevel > this.currentLevel)
				{
					this.currentLevel = newLevel
					this.onLevelUp()
				}
				else
				{
					this.currentLevel = newLevel
				}
			}

			if (this.expEl && data.expNeededText)
			{
				this.expEl.textContent = data.expNeededText
			}
		},

		onLevelUp ()
		{
			if (this.options.soundEnabled && this.options.levelUpSound && window.Audio)
			{
				const sound = new Audio(XF.canonicalizeUrl(this.options.levelUpSound))
				const playing = sound.play()
				if (playing && typeof playing.catch === 'function')
				{
					// Autoplay policies may still refuse; the level-up is shown regardless.
					playing.catch(() => {})
				}
			}

			XF.trigger(this.target, XF.customEvent('userpets:level-change', {
				level: this.currentLevel,
			}))
		},

		setButtonsEnabled (enabled)
		{
			this.buttons.forEach(button =>
			{
				button.disabled = !enabled
				button.classList.toggle('is-disabled', !enabled)
				if (enabled)
				{
					button.removeAttribute('aria-disabled')
				}
				else
				{
					button.setAttribute('aria-disabled', 'true')
				}
			})
		},

		startCooldown (seconds)
		{
			seconds = parseInt(seconds, 10) || 0
			if (seconds <= 0)
			{
				this.endCooldown()
				return
			}

			if (this.cooldownTimer)
			{
				clearInterval(this.cooldownTimer)
			}

			// Counting against a fixed end time keeps the display right when the
			// browser throttles timers in background tabs.
			this.cooldownEnd = Date.now() + (seconds * 1000)
			this.setButtonsEnabled(false)
			this.renderCooldown()

			this.cooldownTimer = setInterval(() => this.renderCooldown(), 1000)
		},

		renderCooldown ()
		{
			const remaining = Math.ceil((this.cooldownEnd - Date.now()) / 1000)
			if (remaining <= 0)
			{
				this.endCooldown()
				return
			}

			if (this.cooldownEl)
			{
				this.cooldownEl.textContent = XF.phrase('sylphian_userpets_cooldown_remaining_x', {
					'{seconds}': remaining,
				})
				this.cooldownEl.hidden = false
			}
		},

		endCooldown ()
		{
			if (this.cooldownTimer)
			{
				clearInterval(this.cooldownTimer)
				this.cooldownTimer = null
			}

			if (this.cooldownEl)
			{
				this.cooldownEl.hidden = true
				this.cooldownEl.textContent = ''
			}

			this.setButtonsEnabled(true)
		},
	})

	XF.Element.register('pet-actions', 'XF.PetActions')
})(window, document)
