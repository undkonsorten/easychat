<f:variable name="consentAcceptedMessageLLL" value="{f:translate(key: 'easychat_dataProtectionConsentAcceptedMessage')}"/>
<f:variable name="introMessageLLL" value="{f:translate(key: 'easychat_introMessage')}"/>
<f:variable name="introMessage">{settings.introMessage ?: introMessageLLL}</f:variable>

const easychat = document.querySelector('.easychat');
const EASYCHAT_COOKIE_SESSION = 'easychat_session_id';
const EASYCHAT_COOKIE_NAGSCREEN = 'easychat_nagscreen';
const EASYCHAT_CONSENT_SETTING = '{settings.enableDataProtectionConsent}';
const EASYCHAT_CONSENT_ACCEPTED = '{consentAcceptedMessageLLL}';
const EASYCHAT_INTRO_MESSAGE = `{introMessage}`;
const easychatNagscreen = easychat.querySelector('.easychat__nagscreen');
const easychatToggles = easychat.querySelectorAll('.easychat__toggle');
const chatbot = document.querySelector('.chatbot');
const header = chatbot.querySelector('.chatbot__header');
const deepchat = document.getElementById('chat-assistant');
let easychatSession = getCookie(EASYCHAT_COOKIE_SESSION);

if (easychatNagscreen) {
	if (getCookie(EASYCHAT_COOKIE_NAGSCREEN) === false) {
		easychatNagscreen.hidden = false;
	}

	easychatNagscreen.querySelector('button').addEventListener('click', (e) => {
		e.stopPropagation();
		easychatNagscreen.hidden = true;
		setCookie(EASYCHAT_COOKIE_NAGSCREEN, 'hidden', '{settings.cookieExpirationMinutes}');
	});

	easychatNagscreen.addEventListener('click', () => {
		chatbot.hidden = false;
		easychatNagscreen.hidden = true;
		for (const easychatToggle of easychatToggles) {
			easychatToggle.ariaExpanded = true;
		}
		focusDeepChat();
	});
}

function focusDeepChat() {
	if (!deepchat.hidden) {
		requestAnimationFrame(() => deepchat.focusInput());
	}
}

for (const easychatToggle of easychatToggles) {
	easychatToggle.addEventListener('click', function() {
		const opening = chatbot.hidden;
		chatbot.hidden = !chatbot.hidden;
		if (easychatNagscreen) easychatNagscreen.hidden = true;

		for (const easychatToggle of easychatToggles) {
			easychatToggle.ariaExpanded = !chatbot.hidden;
		}

		const revoke = easychat.querySelector('.revoke');
		if (revoke && EASYCHAT_CONSENT_SETTING === 'consent') {
			revoke.hidden = opening ? getCookie(EASYCHAT_COOKIE_SESSION) === false : true;
		}

		if (opening) focusDeepChat();
	});
}

if (EASYCHAT_CONSENT_SETTING === 'consent') {
	const consent = chatbot.querySelector('.chatbot__consent');
	const consentButton = consent?.querySelector('.consent-button');
	const revoke = easychat.querySelector('.revoke');
	const revokeButton = revoke?.querySelector('.revoke-button');

	if (easychatSession === false) {
		if (consent) consent.hidden = false;
	} else {
		deepchat.hidden = false;
		if (header) header.hidden = false;
		if (revoke) revoke.hidden = chatbot.hidden;
	}

	if (consentButton) {
		consentButton.addEventListener('click', () => {
			setCookie(EASYCHAT_COOKIE_SESSION, self.crypto.randomUUID(), '{settings.cookieExpirationMinutes}');
			if (consent) consent.hidden = true;
			deepchat.hidden = false;
			if (header) header.hidden = false;
			if (revoke) revoke.hidden = false;
		});
	}

	if (revokeButton) {
		revokeButton.addEventListener('click', () => {
			removeCookie('easychat_session_id');
			if (consent) consent.hidden = false;
			deepchat.hidden = true;
			if (header) header.hidden = true;
			if (revoke) revoke.hidden = true;
		});
	}

	deepchat.addEventListener('render', (e) => {
		addIntroMessage(deepchat);

		if (consentButton) {
			consentButton.addEventListener('click', () => {
				deepchat.focusInput();
				deepchat.clearMessages();
				deepchat.addMessage({text: EASYCHAT_CONSENT_ACCEPTED, role: "moderator"});
				addIntroMessage(deepchat);
			});
		}
	});
} else if (EASYCHAT_CONSENT_SETTING === 'without') {

	if (easychatSession === false) {
		setCookie(EASYCHAT_COOKIE_SESSION, self.crypto.randomUUID(), '{settings.cookieExpirationMinutes}');
		easychatSession = getCookie(EASYCHAT_COOKIE_SESSION)
		console.log(easychatSession)
	}
	deepchat.hidden = false;
	if (header) header.hidden = false;

	deepchat.addEventListener('render', (e) => {
		addIntroMessage(deepchat);
	});
} else {
	if (easychatSession !== false) {
		deepchat.hidden = false;
		if (header) header.hidden = false;

		deepchat.addEventListener('render', (e) => {
			addIntroMessage(deepchat);
		});
	} else {
		chatbot.querySelector('.chatbot__stage').innerHTML = `<p style="background-color: yellow; padding: 0.5em 1em;">To run the chat assistant, your cookie management needs to set a cookie named <b>easychat_session_id</b>.</p>`;
	}
}

function addIntroMessage(chatbotReference) {
	chatbotReference.addMessage({text: EASYCHAT_INTRO_MESSAGE.replace(/\r?\n/g, "\n")});
}

function setCookie(cname, cvalue, exminutes) {
	const d = new Date();
	d.setTime(d.getTime() + (exminutes * 60 * 1000));
	let expires = "expires=" + d.toUTCString();
	document.cookie = cname + "=" + cvalue + ";" + expires + ";path=/";
}

function removeCookie(cname) {
	document.cookie = cname + "=; expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/";
}

function getCookie(cname) {
	let name = cname + "=";
	let ca = document.cookie.split(';');
	for (let i = 0; i < ca.length; i++) {
		let c = ca[i];
		while (c.charAt(0) === ' ') {
			c = c.substring(1);
		}
		if (c.indexOf(name) === 0) {
			return c.substring(name.length, c.length);
		}
	}
	return false;
}

