@props([
    'role' => 'HR',
    'page' => 'dashboard',
    'applicantId' => null,
    'employeeName' => null,
])

<div
    class="hr-robot"
    data-robot
    data-role="{{ $role }}"
    data-page="{{ $page }}"
    data-applicant-id="{{ $applicantId }}"
    data-employee-name="{{ $employeeName }}"
    data-csrf="{{ csrf_token() }}"
    data-announce="{{ session('success') ? '1' : '' }}"
>

    {{-- Speech bubble (hidden until the robot has something to announce) --}}
    <div class="robot-notice" data-robot-notice hidden>
        <div class="robot-notice-title" data-robot-notice-title></div>
        <div class="robot-list compact" data-robot-notice-list></div>
    </div>

    {{-- Floating avatar --}}
    <button
        type="button"
        class="robot-avatar"
        data-robot-toggle
        aria-label="Open assistant"
        aria-expanded="false"
    >

        <svg viewBox="0 0 80 80" aria-hidden="true">
            <!-- Head -->
            <circle cx="40" cy="30" r="19" />

            <!-- Eyes -->
            <circle cx="33" cy="29" r="2.5" class="robot-eye" />
            <circle cx="47" cy="29" r="2.5" class="robot-eye" />

            <!-- Mouth -->
            <path d="M32 38 Q40 44 48 38" fill="none" stroke-width="2" stroke-linecap="round" />

            <!-- Body -->
            <path d="M22 70 Q23 51 40 49 Q57 51 58 70" />

            <!-- Shoulders -->
            <path d="M15 69 Q23 61 30 61" />
            <path d="M65 69 Q57 61 50 61" />

            <!-- Small signal -->
            <path d="M40 7 L40 2" />
            <circle cx="40" cy="1.5" r="1.5" />
        </svg>

        {{-- Unread count --}}
        <span class="robot-badge" data-robot-badge hidden>0</span>

    </button>


    {{-- Expanded panel --}}
    <div class="robot-panel" data-robot-panel hidden>

        <div class="robot-panel-header">

            <div>
                <strong>HRIS Assistant</strong>
                <small data-robot-role>{{ $role }}</small>
            </div>

            <button type="button" data-robot-close class="robot-close" aria-label="Close">
                &times;
            </button>

        </div>


        {{-- Scrollable area: status first, then the conversation --}}
        <div class="robot-panel-body" data-robot-body>

            <div class="robot-title" data-robot-title>Loading...</div>

            <div class="robot-list" data-robot-list></div>

            <div class="robot-priority" data-robot-priority>NORMAL</div>

            <button
                type="button"
                class="robot-read"
                data-robot-read
                hidden
            >
                Mark as read
            </button>

            <div class="robot-chat-log" data-robot-log></div>

        </div>


        {{-- Always visible: suggestions + the question box --}}
        <div class="robot-footer">

            <div class="robot-chips" data-robot-chips></div>

            <form class="robot-form" data-robot-form autocomplete="off">
                <input
                    type="text"
                    class="robot-input"
                    data-robot-input
                    placeholder="Type your question"
                    maxlength="300"
                    aria-label="Ask the assistant"
                >

                <button type="submit" class="robot-send" data-robot-send>
                    Send
                </button>
            </form>

        </div>

    </div>

</div>


<style>
    .hr-robot {
        position: fixed;
        right: 24px;
        bottom: 24px;
        z-index: 9999;
        font-family: Arial, sans-serif;
    }

    /* The hidden attribute must win over the display rules below */
    .hr-robot [hidden] {
        display: none !important;
    }

    .robot-avatar {
        position: relative;
        width: 72px;
        height: 72px;
        border: 0;
        border-radius: 50%;
        background: #2e2e2e;
        color: white;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.20);
        transition: transform 0.2s ease;
    }

    .robot-avatar:hover {
        transform: translateY(-3px);
    }

    .robot-avatar svg {
        width: 56px;
        height: 56px;
    }

    .robot-avatar svg circle,
    .robot-avatar svg path {
        stroke: currentColor;
        fill: none;
        stroke-width: 2.5;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .robot-avatar .robot-eye {
        fill: currentColor;
        stroke: none;
    }

    /* ---------- Animations ---------- */

    .robot-avatar.robot-alert {
        animation: robot-wiggle 0.9s ease 2;
    }

    @keyframes robot-wiggle {
        0%, 100% { transform: rotate(0) scale(1); }
        15%      { transform: rotate(-14deg) scale(1.1); }
        30%      { transform: rotate(12deg) scale(1.1); }
        45%      { transform: rotate(-8deg) scale(1.05); }
        60%      { transform: rotate(6deg) scale(1.05); }
        75%      { transform: rotate(-3deg) scale(1); }
    }

    .robot-has-event .robot-avatar::before {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: 50%;
        border: 3px solid #e5322d;
        pointer-events: none;
        animation: robot-ring 1.6s ease-out infinite;
    }

    @keyframes robot-ring {
        0%   { transform: scale(1);   opacity: 0.8; }
        100% { transform: scale(1.6); opacity: 0; }
    }

    .robot-notice {
        transform-origin: bottom right;
        animation: robot-pop 0.35s cubic-bezier(0.2, 1.4, 0.4, 1);
    }

    @keyframes robot-pop {
        0%   { transform: scale(0.6) translateX(20px); opacity: 0; }
        100% { transform: scale(1) translateX(0);      opacity: 1; }
    }

    @keyframes robot-row-in {
        0%   { opacity: 0; transform: translateY(6px); }
        100% { opacity: 1; transform: translateY(0); }
    }

    /* Blinking cursor while the robot is typing */
    .robot-typing::after {
        content: '|';
        margin-left: 2px;
        font-weight: 400;
        animation: robot-blink 0.7s steps(1) infinite;
    }

    @keyframes robot-blink {
        0%, 50%      { opacity: 1; }
        50.01%, 100% { opacity: 0; }
    }

    /* "Thinking" dots while waiting for an answer */
    .robot-dots {
        padding: 6px 2px;
    }

    .robot-dots span {
        display: inline-block;
        width: 7px;
        height: 7px;
        margin-right: 4px;
        border-radius: 50%;
        background: #999;
        animation: robot-dot 1s infinite;
    }

    .robot-dots span:nth-child(2) { animation-delay: 0.15s; }
    .robot-dots span:nth-child(3) { animation-delay: 0.3s; }

    @keyframes robot-dot {
        0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
        30%           { transform: translateY(-4px); opacity: 1; }
    }

    @media (prefers-reduced-motion: reduce) {
        .robot-avatar.robot-alert,
        .robot-has-event .robot-avatar::before,
        .robot-notice,
        .robot-item,
        .robot-dots span,
        .robot-typing::after {
            animation: none !important;
        }

        .robot-item {
            opacity: 1;
        }
    }

    /* ---------- Badge ---------- */

    .robot-badge {
        position: absolute;
        top: -4px;
        right: -4px;
        min-width: 22px;
        height: 22px;
        padding: 0 6px;
        box-sizing: border-box;
        border-radius: 999px;
        background: #e5322d;
        color: white;
        border: 2px solid white;
        font-size: 12px;
        font-weight: 700;
        line-height: 18px;
        text-align: center;
    }

    /* ---------- Bubble ---------- */

    .robot-notice {
        position: absolute;
        right: 84px;
        bottom: 10px;
        width: 290px;
        background: white;
        border: 1px solid #e4e4e4;
        border-radius: 14px;
        padding: 14px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
        cursor: pointer;
    }

    .robot-notice-title {
        font-weight: 700;
        font-size: 14px;
        margin-bottom: 8px;
        min-height: 20px;
    }

    /* ---------- Panel ---------- */

    .robot-panel {
        position: absolute;
        right: 0;
        bottom: 86px;
        width: 360px;
        max-height: min(80vh, 640px);
        display: flex;
        flex-direction: column;
        background: white;
        border: 1px solid #e4e4e4;
        border-radius: 16px;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.16);
        overflow: hidden;
    }

    .robot-panel-header {
        flex-shrink: 0;
        padding: 15px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid #eee;
    }

    .robot-panel-header small {
        display: block;
        margin-top: 3px;
        color: #777;
        text-transform: uppercase;
        font-size: 11px;
        letter-spacing: 0.04em;
    }

    .robot-close {
        border: 0;
        background: transparent;
        font-size: 24px;
        cursor: pointer;
        color: #555;
    }

    .robot-panel-body {
        flex: 1 1 auto;
        min-height: 0;
        padding: 16px;
        overflow-y: auto;
    }

    .robot-title {
        font-size: 15px;
        font-weight: 700;
        margin-bottom: 12px;
        min-height: 22px;
    }

    .robot-title.small {
        font-size: 14px;
        margin-bottom: 8px;
        min-height: 18px;
    }

    /* ---------- Item rows ---------- */

    .robot-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
        margin-bottom: 14px;
    }

    .robot-list.compact {
        margin-bottom: 0;
        gap: 6px;
    }

    .robot-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        border-radius: 8px;
        border-left: 4px solid #cfcfcf;
        background: #f7f7f7;
        color: #222;
        text-decoration: none;
        font-size: 14px;
        line-height: 1.4;
        opacity: 0;
        animation: robot-row-in 0.3s ease forwards;
    }

    .robot-item .body {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    /* Small label above a row: Source, Application, Attendance... */
    .robot-item .tag {
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: #7a7a7a;
    }

    .robot-item .text {
        word-break: break-word;
    }

    .robot-item .go {
        flex-shrink: 0;
        font-size: 12px;
        font-weight: 700;
        color: #2f6fd0;
    }

    a.robot-item:hover {
        background: #eee;
    }

    .robot-item.level-critical { border-left-color: #e5322d; background: #fdf3f2; }
    .robot-item.level-high     { border-left-color: #e08e0b; background: #fff8ec; }
    .robot-item.level-medium   { border-left-color: #2f7de1; background: #f1f6fd; }
    .robot-item.level-low      { border-left-color: #cfcfcf; background: #f7f7f7; }

    .robot-list.compact .robot-item {
        padding: 7px 10px;
        font-size: 13px;
    }

    .robot-more {
        font-size: 12px;
        color: #888;
        padding-left: 4px;
    }

    .robot-priority {
        display: inline-block;
        padding: 5px 9px;
        border-radius: 999px;
        background: #f1f1f1;
        font-size: 11px;
        font-weight: 700;
        margin-bottom: 14px;
    }

    .robot-read {
        display: block;
        width: 100%;
        box-sizing: border-box;
        text-align: center;
        padding: 10px 12px;
        border-radius: 9px;
        background: #2e2e2e;
        color: white;
        font-size: 14px;
        border: 0;
        cursor: pointer;
    }

    .robot-read:hover {
        opacity: 0.9;
    }

    /* ---------- Conversation ---------- */

    .robot-chat-log {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .robot-chat-log:not(:empty) {
        margin-top: 16px;
        padding-top: 14px;
        border-top: 1px solid #eee;
    }

    .robot-msg.me {
        align-self: flex-end;
        max-width: 85%;
        padding: 8px 12px;
        border-radius: 14px 14px 4px 14px;
        background: #2e2e2e;
        color: #fff;
        font-size: 14px;
        line-height: 1.4;
        word-break: break-word;
    }

    .robot-msg.bot {
        align-self: stretch;
    }

    .robot-msg.bot .robot-list {
        margin-bottom: 0;
    }

    /* ---------- Footer: suggestions + input ---------- */

    .robot-footer {
        flex-shrink: 0;
        padding: 10px 12px 12px;
        border-top: 1px solid #eee;
        background: #fff;
    }

    .robot-chips {
        display: flex;
        gap: 6px;
        margin-bottom: 8px;
        padding-bottom: 2px;
        overflow-x: auto;
        scrollbar-width: thin;
    }

    .robot-chip {
        flex-shrink: 0;
        padding: 6px 12px;
        border: 1px solid #d8d8d8;
        border-radius: 999px;
        background: #fff;
        color: #444;
        font-size: 12px;
        white-space: nowrap;
        cursor: pointer;
    }

    .robot-chip:hover {
        background: #f3f3f3;
        border-color: #bbb;
    }

    .robot-form {
        display: flex;
        gap: 8px;
    }

    .robot-input {
        flex: 1;
        min-width: 0;
        padding: 10px 14px;
        border: 1px solid #ccc;
        border-radius: 999px;
        font-size: 14px;
        font-family: inherit;
    }

    .robot-input:focus {
        outline: 2px solid #2e2e2e;
        border-color: transparent;
    }

    .robot-send {
        padding: 0 16px;
        border: 0;
        border-radius: 999px;
        background: #2e2e2e;
        color: #fff;
        font-size: 14px;
        cursor: pointer;
    }

    .robot-send:disabled,
    .robot-input:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    @media (max-width: 600px) {
        .hr-robot {
            right: 14px;
            bottom: 14px;
        }

        .robot-notice {
            right: 0;
            bottom: 82px;
            width: min(290px, calc(100vw - 28px));
        }

        .robot-panel {
            right: 0;
            bottom: 82px;
            width: min(360px, calc(100vw - 28px));
        }
    }
</style>


<script>
    (() => {
        const robot = document.querySelector('[data-robot]');
        if (!robot) return;

        const role = robot.dataset.role;
        const page = robot.dataset.page;
        const applicantId = robot.dataset.applicantId;
        const employeeName = robot.dataset.employeeName;
        const csrf = robot.dataset.csrf;
        const announceOnLoad = robot.dataset.announce === '1';

        const avatar = robot.querySelector('[data-robot-toggle]');
        const badge = robot.querySelector('[data-robot-badge]');
        const panel = robot.querySelector('[data-robot-panel]');
        const panelBody = robot.querySelector('[data-robot-body]');
        const closeButton = robot.querySelector('[data-robot-close]');
        const notice = robot.querySelector('[data-robot-notice]');
        const noticeTitle = robot.querySelector('[data-robot-notice-title]');
        const noticeList = robot.querySelector('[data-robot-notice-list]');
        const title = robot.querySelector('[data-robot-title]');
        const list = robot.querySelector('[data-robot-list]');
        const priority = robot.querySelector('[data-robot-priority]');
        const readButton = robot.querySelector('[data-robot-read]');

        const log = robot.querySelector('[data-robot-log]');
        const chips = robot.querySelector('[data-robot-chips]');
        const form = robot.querySelector('[data-robot-form]');
        const input = robot.querySelector('[data-robot-input]');
        const sendButton = robot.querySelector('[data-robot-send]');

        const reduceMotion = window.matchMedia(
            '(prefers-reduced-motion: reduce)'
        ).matches;

        const TITLE_MS = 16; // typing speed for titles
        const TEXT_MS = 18;  // typing speed for rows
        const CHAT_MS = 9;   // typing speed for answers to questions

        const CHIPS = {
            HR: [
                'How many applicants today?',
                'Pending applications',
                'Who has an interview this week?',
                'Who is on duty now?',
                'Any expired documents?',
                'Worked hours this week',
                'What needs my attention?',
            ],
            Employee: [
                'My worked hours this week',
                'Total worked hours',
                'Which documents expire soon?',
            ],
            Applicant: [
                'What is my application status?',
                'What is my next step?',
                'When is my interview?',
            ],
        };

        let seenEventId = null;
        let currentEventId = null;
        let currentSignature = null;
        let lastSignature = null;
        let currentData = null;
        let noticeTimer = null;
        let guided = false; // when true, the page controls what the robot says
        let asking = false;

        // A new "token" per list cancels any typing still running in it.
        const listTokens = new WeakMap();
        const titleTimers = new WeakMap();

        /* ---------------- Typing ---------------- */

        function typeTitle(element, text, typing, ms = TITLE_MS) {
            const previous = titleTimers.get(element);

            if (previous) {
                clearInterval(previous.timer);
                previous.resolve();
            }

            element.classList.remove('robot-typing');

            if (!typing || reduceMotion) {
                element.textContent = text;
                return Promise.resolve();
            }

            element.textContent = '';
            element.classList.add('robot-typing');

            return new Promise((resolve) => {
                let i = 0;

                const timer = setInterval(() => {
                    i += 1;
                    element.textContent = text.slice(0, i);

                    if (i >= text.length) {
                        clearInterval(timer);
                        element.classList.remove('robot-typing');
                        titleTimers.delete(element);
                        resolve();
                    }
                }, ms);

                titleTimers.set(element, { timer, resolve });
            });
        }

        function typeText(element, full, container, token, ms = TEXT_MS) {
            return new Promise((resolve) => {
                element.classList.add('robot-typing');

                let i = 0;

                const timer = setInterval(() => {
                    // A newer message replaced this one: stop quietly.
                    if (listTokens.get(container) !== token) {
                        clearInterval(timer);
                        element.classList.remove('robot-typing');
                        resolve();
                        return;
                    }

                    i += 1;
                    element.textContent = full.slice(0, i);

                    if (i >= full.length) {
                        clearInterval(timer);
                        element.classList.remove('robot-typing');
                        resolve();
                    }
                }, ms);
            });
        }

        // Build one row with textContent so server text can never inject HTML
        function buildRow(item, links) {
            const hasLink = links && item.url;
            const row = document.createElement(hasLink ? 'a' : 'div');

            row.className = `robot-item level-${item.level || 'low'}`;

            if (hasLink) row.href = item.url;

            const body = document.createElement('span');
            body.className = 'body';

            if (item.tag) {
                const tag = document.createElement('span');
                tag.className = 'tag';
                tag.textContent = item.tag;
                body.append(tag);
            }

            const text = document.createElement('span');
            text.className = 'text';
            body.append(text);

            row.append(body);

            if (hasLink) {
                const go = document.createElement('span');
                go.className = 'go';
                go.textContent = 'View';
                row.append(go);
            }

            return { row, text };
        }

        async function renderItems(container, items, token, options) {
            const {
                links = true,
                max = null,
                typing = true,
                speed = TEXT_MS,
            } = options;

            const shown = max ? items.slice(0, max) : items;

            for (let index = 0; index < shown.length; index += 1) {
                if (listTokens.get(container) !== token) return;

                const item = shown[index];
                const { row, text } = buildRow(item, links);
                const animate = typing && !reduceMotion;

                // Instant mode: rows fade in one after another.
                if (!animate) row.style.animationDelay = `${index * 80}ms`;

                container.append(row);

                if (animate) {
                    await typeText(text, item.text, container, token, speed);
                } else {
                    text.textContent = item.text;
                }
            }

            if (listTokens.get(container) !== token) return;

            if (max && items.length > max) {
                const more = document.createElement('div');
                more.className = 'robot-more';
                more.textContent = `+${items.length - max} more`;
                container.append(more);
            }
        }

        // Title first, then the rows, one by one.
        async function paint(titleElement, listElement, data, options = {}) {
            const token = Symbol('paint');

            listTokens.set(listElement, token);
            listElement.replaceChildren();

            await typeTitle(
                titleElement,
                data.title,
                options.typing !== false,
                options.speed ?? TITLE_MS
            );

            if (listTokens.get(listElement) !== token) return;

            await renderItems(listElement, data.items, token, options);
        }

        /* ---------------- Bubble ---------------- */

        function hideNotice() {
            clearTimeout(noticeTimer);
            listTokens.set(noticeList, Symbol('stop'));
            notice.hidden = true;
        }

        // Wiggle the avatar, pop the bubble in, and type the message
        function announce(data) {
            if (!panel.hidden) return;

            // Restart the animations every time
            notice.hidden = true;
            void notice.offsetWidth;
            notice.hidden = false;

            avatar.classList.remove('robot-alert');
            void avatar.offsetWidth;
            avatar.classList.add('robot-alert');

            paint(noticeTitle, noticeList, data, {
                links: false,
                max: 3,
                typing: true,
            });

            clearTimeout(noticeTimer);
            noticeTimer = setTimeout(hideNotice, 12000);
        }

        avatar.addEventListener('animationend', (event) => {
            if (event.animationName === 'robot-wiggle') {
                avatar.classList.remove('robot-alert');
            }
        });

        notice.addEventListener('click', () => openPanel());

        // On the first load, speak up about important things,
        // but only once per message per browser session.
        function shouldGreet(signature, level) {
            if (!['critical', 'high'].includes(level)) return false;

            const key = `robot-greeted:${role}:${signature}`;

            try {
                if (sessionStorage.getItem(key)) return false;
                sessionStorage.setItem(key, '1');
            } catch (error) {
                // sessionStorage unavailable: fall through and greet
            }

            return true;
        }

        /* ---------------- Server updates ---------------- */

        async function updateRobot({ forceAnnounce = false } = {}) {
            // A page that guides the robot itself (applicant form) owns the message.
            if (guided) return;

            const params = new URLSearchParams({ role, page });
            if (applicantId) params.set('applicant_id', applicantId);
            if (employeeName) params.set('employee_name', employeeName);

            try {
                const response = await fetch(`/robot/context?${params}`);
                if (!response.ok) throw new Error('Robot context request failed.');

                const raw = await response.json();

                // The page may have taken over while the request was in flight.
                if (guided) return;

                // Older controller responses only had "message"
                const data = {
                    ...raw,
                    title: raw.title || 'Assistant',
                    items: Array.isArray(raw.items) && raw.items.length
                        ? raw.items
                        : [{ text: raw.message, level: raw.priority }],
                };

                const signature =
                    data.title + '|' + data.items.map((i) => i.text).join('|');

                // Only repaint the panel when something changed.
                // Type it only if the panel is open; otherwise it is
                // replayed with typing the next time it opens.
                if (signature !== currentSignature) {
                    currentSignature = signature;
                    currentData = { title: data.title, items: data.items };

                    paint(title, list, currentData, { typing: !panel.hidden });
                }

                priority.textContent = data.priority.toUpperCase();

                // Mark-as-read button only when there is an unread event
                currentEventId = data.event_id;
                readButton.hidden = !currentEventId;

                // Badge + pulsing ring
                if (data.unread_count > 0) {
                    badge.textContent = data.unread_count;
                    badge.hidden = false;
                    robot.classList.add('robot-has-event');
                } else {
                    badge.hidden = true;
                    robot.classList.remove('robot-has-event');
                }

                // Decide whether the robot should speak
                const isFirstLoad = lastSignature === null;

                const isNewEvent =
                    data.event_id && data.event_id !== seenEventId;

                const changed = !isFirstLoad && signature !== lastSignature;

                const greet =
                    isFirstLoad && shouldGreet(signature, data.priority);

                if (data.event_id) {
                    seenEventId = data.event_id;
                }

                lastSignature = signature;

                if (forceAnnounce || isNewEvent || changed || greet) {
                    announce(currentData);
                }

            } catch (error) {
                console.error(error);

                if (!guided) {
                    listTokens.set(list, Symbol('error'));
                    title.textContent = 'The assistant is temporarily unavailable.';
                    list.replaceChildren();
                }
            }
        }

        /* ---------------- Panel ---------------- */

        function openPanel() {
            panel.hidden = false;
            avatar.setAttribute('aria-expanded', 'true');
            hideNotice();

            // Type the current message every time the panel opens
            if (currentData) {
                paint(title, list, currentData, { typing: true });
            }

            updateRobot();
        }

        function closePanel() {
            panel.hidden = true;
            avatar.setAttribute('aria-expanded', 'false');
        }

        avatar.addEventListener('click', () => {
            panel.hidden ? openPanel() : closePanel();
        });

        closeButton.addEventListener('click', closePanel);

        readButton.addEventListener('click', async () => {
            if (!currentEventId) return;

            readButton.disabled = true;

            try {
                const response = await fetch(
                    `/robot/events/${currentEventId}/read`,
                    {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrf,
                            'Accept': 'application/json',
                        },
                    }
                );

                if (!response.ok) throw new Error('Could not mark as read.');

                await updateRobot();

            } catch (error) {
                console.error(error);
            } finally {
                readButton.disabled = false;
            }
        });

        /* ---------------- Conversation ---------------- */

        function scrollDown() {
            panelBody.scrollTop = panelBody.scrollHeight;
        }

        function addUserMessage(text) {
            const element = document.createElement('div');
            element.className = 'robot-msg me';
            element.textContent = text;

            log.append(element);
            scrollDown();
        }

        function addThinking() {
            const element = document.createElement('div');
            element.className = 'robot-msg bot';

            const dots = document.createElement('div');
            dots.className = 'robot-dots';
            dots.append(
                document.createElement('span'),
                document.createElement('span'),
                document.createElement('span')
            );

            element.append(dots);
            log.append(element);
            scrollDown();

            return element;
        }

        function addAnswer(data) {
            const element = document.createElement('div');
            element.className = 'robot-msg bot';

            const answerTitle = document.createElement('div');
            answerTitle.className = 'robot-title small';

            const answerList = document.createElement('div');
            answerList.className = 'robot-list';

            element.append(answerTitle, answerList);
            log.append(element);

            // Keep the newest line in view while it types
            const keepInView = setInterval(scrollDown, 120);

            paint(
                answerTitle,
                answerList,
                { title: data.title, items: data.items || [] },
                { typing: true, speed: CHAT_MS }
            ).finally(() => {
                clearInterval(keepInView);
                scrollDown();
            });
        }

        function setBusy(on) {
            input.disabled = on;
            sendButton.disabled = on;
        }

        async function ask(question) {
            const text = question.trim();

            if (!text || asking) return;

            asking = true;
            input.value = '';
            setBusy(true);

            addUserMessage(text);
            const thinking = addThinking();

            try {
                const response = await fetch('/robot/ask', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({
                        question: text,
                        role,
                        employee_name: employeeName || null,
                    }),
                });

                if (response.status === 429) {
                    throw new Error('slow-down');
                }

                if (!response.ok) {
                    throw new Error('Robot ask request failed.');
                }

                const data = await response.json();

                thinking.remove();
                addAnswer(data);

            } catch (error) {
                console.error(error);

                thinking.remove();

                addAnswer({
                    title: 'Sorry',
                    items: [{
                        text: error.message === 'slow-down'
                            ? 'You are asking too fast. Please wait a moment.'
                            : 'I could not answer right now. Please try again.',
                        level: 'medium',
                    }],
                });

            } finally {
                asking = false;
                setBusy(false);
                input.focus();
            }
        }

        function renderChips() {
            chips.replaceChildren();

            (CHIPS[role] || []).forEach((label) => {
                const chip = document.createElement('button');

                chip.type = 'button';
                chip.className = 'robot-chip';
                chip.textContent = label;
                chip.addEventListener('click', () => ask(label));

                chips.append(chip);
            });
        }

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            ask(input.value);
        });

        renderChips();

        /* ---------------- Page-driven guide (applicant form) ---------------- */

        function say(data, { speak = true } = {}) {
            const items = data.items || [];
            const signature = data.title + '|' + items.map((i) => i.text).join('|');

            // Same message as before: do nothing, so the robot never repeats itself
            if (signature === currentSignature) return;

            currentSignature = signature;
            lastSignature = signature;
            currentData = { title: data.title, items };

            priority.textContent = (data.level || 'medium').toUpperCase();
            readButton.hidden = true;

            paint(title, list, currentData, { typing: !panel.hidden });

            if (speak) announce(currentData);
        }

        window.HrisRobot = {
            guide(on = true) { guided = on; },
            say,
        };

        // First load
        updateRobot({ forceAnnounce: announceOnLoad });

        // Check for new events every 10 seconds.
        setInterval(() => updateRobot(), 10000);
    })();
</script>