XF.PetActions = XF.Element.newHandler({
    options: {
        actionUrl: null,
        cooldown: 0
    },

    container: null,
    buttons: null,
    cooldownTimer: null,
    cooldownDisplay: null,
    cooldownInterval: null,

    init: function() {
        this.container = this.target;

        this.buttons = this.container.querySelectorAll('.pet-action-button');
        this.cooldownDisplay = this.container.querySelector('.cooldown-timer');
        this.cooldownTimer = this.container.querySelector('.js-pet-cooldown-timer');

        this.currentLevel = parseInt(this.container.querySelector('.petStats .u-alignCenter div:first-child').textContent.match(/\d+/)[0]);

        for (let i = 0; i < this.buttons.length; i++) {
            this.buttons[i].addEventListener('click', this.performAction.bind(this));
        }

        this.checkExistingCooldown();
    },

    performAction: function(e) {
        e.preventDefault();

        if (e.currentTarget.classList.contains('button--disabled')) {
            return;
        }

        const action = e.currentTarget.getAttribute('data-action');

        XF.ajax('post', this.options.actionUrl, {
            action: action
        }, XF.proxy(this, 'actionComplete'), {
            skipDefaultSuccess: true
        });

        this.disableButtons();
    },

    actionComplete: function(data) {
        if (data.success) {
            this.updatePetStats(data);

            const cooldownTime = data.cooldownTime || this.options.cooldown;
            this.startCooldownTimer(cooldownTime);

            XF.flashMessage(data.message || XF.phrase('sylphian_userpets_action_success'), 3000);
        } else {
            XF.alert(data.message || XF.phrase('sylphian_userpets_action_error'));

            if (data.cooldownRemaining) {
                this.startCooldownTimer(data.cooldownRemaining);
            } else {
                this.enableButtons();
            }
        }
    },

    updatePetStats: function(data) {
        const hungerBar = this.container.querySelector('.petStatBars .petStatBar:nth-child(1) .petProgressBar');
        if (hungerBar && data.hunger !== undefined) {
            hungerBar.setAttribute('title', data.hunger + '/100');
            hungerBar.querySelector('.petProgressBar-progress').style.width = data.hunger + '%';
            hungerBar.querySelector('.petProgressBar-label').textContent = data.hunger + '/100';
        }

        const happinessBar = this.container.querySelector('.petStatBars .petStatBar:nth-child(2) .petProgressBar');
        if (happinessBar && data.happiness !== undefined) {
            happinessBar.setAttribute('title', data.happiness + '/100');
            happinessBar.querySelector('.petProgressBar-progress').style.width = data.happiness + '%';
            happinessBar.querySelector('.petProgressBar-label').textContent = data.happiness + '/100';
        }

        const sleepinessBar = this.container.querySelector('.petStatBars .petStatBar:nth-child(3) .petProgressBar');
        if (sleepinessBar && data.sleepiness !== undefined) {
            sleepinessBar.setAttribute('title', data.sleepiness + '/100');
            sleepinessBar.querySelector('.petProgressBar-progress').style.width = data.sleepiness + '%';
            sleepinessBar.querySelector('.petProgressBar-label').textContent = data.sleepiness + '/100';
        }

        if (data.levelProgress !== undefined && data.experience !== undefined) {
            const levelBar = this.container.querySelector('.levelProgressBar');
            if (levelBar) {
                levelBar.querySelector('.petProgressBar-progress').style.width = data.levelProgress + '%';

                const expNeeded = data.expNeeded || 0;
                const totalExpForNextLevel = data.experience + expNeeded;

                levelBar.querySelector('.petProgressBar-label').textContent = data.experience + ' / ' + totalExpForNextLevel;

                levelBar.setAttribute('title', XF.phrase('sylphian_userpets_exp_needed', {exp: expNeeded}));

                if (data.level !== undefined) {
                    const levelText = this.container.querySelector('.petStats .u-alignCenter div:first-child');
                    if (levelText) {
                        levelText.textContent = data.levelText;

                        const newLevel = parseInt(data.level);
                        if (newLevel > this.currentLevel) {
                            this.currentLevel = newLevel;

                            this.triggerLevelUp();
                        } else {
                            this.currentLevel = newLevel;
                        }
                    }
                }

                const levelInfo = this.container.querySelector('.petLevelInfo');
                if (levelInfo && data.expNeededText) {
                    levelInfo.textContent = data.expNeededText;
                }
            }
        }
    },

    triggerLevelUp: function() {
        if (window.Audio) {
            try {
                const sound = new Audio(XF.canonicalizeUrl('data/assets/sylphian/userpets/audio/sparkle-355937.mp3'));
                sound.play();
            } catch (e) {
                console.error('Could not play level up sound', e);
            }
        }
    },

    disableButtons: function() {
        this.buttons.forEach(button => {
            button.classList.add('button--disabled');
            button.disabled = true;
            button.setAttribute('aria-disabled', 'true');
        });
    },

    enableButtons: function() {
        this.buttons.forEach(button => {
           button.classList.remove('button--disabled');
           button.disabled = false;
           button.removeAttribute('aria-disabled');
        });
    },

    checkExistingCooldown: function() {
        const lastActionTime = parseInt(this.container.getAttribute('data-last-action-time') || 0);
        const serverTime = parseInt(this.container.getAttribute('data-current-time') || 0);
        const cooldownTime = this.options.cooldown;

        if (lastActionTime && serverTime) {
            const elapsed = Math.floor((serverTime - lastActionTime));

            if (elapsed < cooldownTime) {
                const remaining = cooldownTime - elapsed;
                this.startCooldownTimer(remaining);
            }
        }
    },

    startCooldownTimer: function(seconds) {
        const self = this;
        let remaining = seconds || this.options.cooldown;

        if (this.cooldownInterval) {
            clearInterval(this.cooldownInterval);
        }

        this.disableButtons();
        this.cooldownDisplay.style.display = '';
        this.cooldownTimer.textContent = remaining;

        this.cooldownInterval = setInterval(function() {
            remaining--;
            self.cooldownTimer.textContent = remaining;

            if (remaining <= 0) {
                clearInterval(self.cooldownInterval);
                self.enableButtons();
                self.cooldownDisplay.style.display = 'none';
            }
        }, 1000);
    }
});

XF.Element.register('pet-actions', 'XF.PetActions');