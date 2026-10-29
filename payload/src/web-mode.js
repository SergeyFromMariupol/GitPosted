(function (root) {
  'use strict';
  const ACTIONS = new Set(['read', 'read_calendar', 'read_target_metadata', 'set_timer', 'set_repeat', 'set_timer_custom', 'set_repeat_custom', 'set_top', 'set_schedule', 'set_targets', 'prepare_publish', 'prepare_schedule', 'confirm', 'operation_state']);
  const OPERATION_STATUSES = new Set(['preparing', 'processing', 'sent', 'scheduled', 'failed', 'not_started']);
  const PENDING_STATUSES = new Set(['preparing', 'processing']);
  const requestIdPattern = /^[a-f0-9]{32}$/;
  const launchPattern = /^[a-f0-9]{48}$/;

  class UiFailure extends Error {}
  class RequestFailure extends Error {
    constructor(message, code, action, reload) { super(message); this.name = 'RequestFailure'; this.code = code; this.action = action; this.reload = reload === true; }
  }
  function userMessage(error) { return error instanceof UiFailure || error instanceof RequestFailure ? error.message : 'Не удалось обновить настройки. Откройте карточку заново из бота.'; }
  function plain(value) { return value !== null && typeof value === 'object' && !Array.isArray(value); }
  function integer(value, minimum) { return Number.isSafeInteger(value) && value >= minimum; }
  function text(value, maximum) { return typeof value === 'string' ? value.slice(0, maximum) : ''; }
  function exactKeys(value, keys) { return plain(value) && Object.keys(value).sort().join(',') === keys.slice().sort().join(','); }
  function validRevision(value) { return integer(value, 0) || (typeof value === 'string' && /^[A-Za-z0-9_.:-]{1,128}$/.test(value)); }
  function safeColor(value) { return typeof value === 'string' && /^#[a-f0-9]{6}$/i.test(value) ? value.toLowerCase() : null; }
  const MAX_TIMER_CUSTOM_MINUTES = 87839;
  const MAX_REPEAT_CUSTOM_MINUTES = 526980;
  function customDurationPayload(kind, values) {
    if (!['timer', 'repeat'].includes(kind)) throw new UiFailure('Недопустимый интервал.');
    const keys = kind === 'timer' ? ['days', 'hours', 'minutes'] : ['days', 'hours'];
    if (!exactKeys(values, keys)) throw new UiFailure('Проверьте интервал.');
    const result = {};
    for (const key of keys) {
      const value = values[key];
      if (typeof value !== 'string' || !/^[0-9]{0,3}$/.test(value)) throw new UiFailure('Введите целые неотрицательные значения.');
      result[key] = value === '' ? 0 : Number(value);
    }
    validateCustomDuration(kind, result); return result;
  }
  function validateCustomDuration(kind, payload) {
    if (!['timer', 'repeat'].includes(kind)) throw new UiFailure('Недопустимый интервал.');
    const keys = kind === 'timer' ? ['days', 'hours', 'minutes'] : ['days', 'hours'];
    const maxDays = kind === 'timer' ? 60 : 365;
    if (!exactKeys(payload, keys) || !integer(payload.days, 0) || payload.days > maxDays || !integer(payload.hours, 0) || payload.hours > 23
      || (kind === 'timer' && (!integer(payload.minutes, 0) || payload.minutes > 59))) throw new UiFailure('Дни: 0–' + maxDays + ', часы: 0–23' + (kind === 'timer' ? ', минуты: 0–59.' : '.'));
    const total = payload.days * 1440 + payload.hours * 60 + (kind === 'timer' ? payload.minutes : 0);
    if (total > (kind === 'timer' ? MAX_TIMER_CUSTOM_MINUTES : MAX_REPEAT_CUSTOM_MINUTES) || (kind === 'repeat' && total !== 0 && (total < 60 || total % 60 !== 0))) throw new UiFailure('Интервал выходит за допустимые пределы.');
    return total;
  }
  function durationPresets(kind) {
    return [0, 60, 120, 360, 1440, kind === 'timer' ? 2875 : 2880, 4320].map((value, index) => ({ value, label: ['Нет', '1 ч', '2 ч', '6 ч', '24 ч', '48 ч', '72 ч'][index] }));
  }
  function durationParts(kind, minutes) {
    if (!integer(minutes, 0) || minutes > (kind === 'timer' ? MAX_TIMER_CUSTOM_MINUTES : MAX_REPEAT_CUSTOM_MINUTES) || (kind === 'repeat' && minutes % 60 !== 0)) return null;
    const logical = kind === 'timer' && minutes === 2875 ? 2880 : minutes;
    const result = { days: Math.floor(logical / 1440), hours: Math.floor((logical % 1440) / 60) };
    if (kind === 'timer') result.minutes = logical % 60; return result;
  }
  function exactDurationLabel(minutes) {
    if (minutes === 0) return 'Нет';
    const days = Math.floor(minutes / 1440), hours = Math.floor((minutes % 1440) / 60), rest = minutes % 60;
    return [days ? days + ' д' : '', hours ? hours + ' ч' : '', rest ? rest + ' мин' : ''].filter(Boolean).join(' ');
  }
  function validDay(value) {
    if (typeof value !== 'string' || !/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/.test(value)) return false;
    const [year, month, day] = value.split('-').map(Number), date = new Date(Date.UTC(year, month - 1, day));
    return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day;
  }
  function validMonth(value) { return typeof value === 'string' && /^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/.test(value); }
  function shiftedMonth(month, offset) {
    if (!validMonth(month) || ![-1, 1].includes(offset)) return null;
    const [year, number] = month.split('-').map(Number), date = new Date(Date.UTC(year, number - 1 + offset, 1));
    return date.getUTCFullYear() < 1000 || date.getUTCFullYear() > 9999 ? null : date.toISOString().slice(0, 7);
  }
  function calendarCells(month) {
    if (!validMonth(month)) throw new UiFailure('Некорректный месяц.');
    const [year, number] = month.split('-').map(Number), first = new Date(Date.UTC(year, number - 1, 1));
    const offset = (first.getUTCDay() + 6) % 7, count = new Date(Date.UTC(year, number, 0)).getUTCDate();
    return Array.from({ length: 42 }, (_, index) => index < offset || index >= offset + count ? null : month + '-' + String(index - offset + 1).padStart(2, '0'));
  }
  function viewerToday(timezone) {
    try {
      const fields = new Intl.DateTimeFormat('en-CA', { timeZone: timezone || 'UTC', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
      const value = key => fields.find(field => field.type === key).value;
      const day = value('year') + '-' + value('month') + '-' + value('day'); return validDay(day) ? day : '';
    } catch (_) { return ''; }
  }
  function scheduleValue(day, hour, minute, today) {
    if (!validDay(day) || (validDay(today) && day < today) || typeof hour !== 'string' || typeof minute !== 'string' || (!hour && !minute)
      || !/^[0-9]{0,2}$/.test(hour) || !/^[0-9]{0,2}$/.test(minute) || Number(hour) > 23 || Number(minute) > 59) throw new UiFailure('Время не задано или меньше текущего.');
    return day + 'T' + String(Number(hour)).padStart(2, '0') + ':' + String(Number(minute)).padStart(2, '0');
  }
  function normalizeCalendar(value, month, day) {
    if (!plain(value) || value.month !== month || value.day !== day || !validMonth(month) || !validDay(day) || day.slice(0, 7) !== month
      || !plain(value.countsByDate) || Object.keys(value.countsByDate).length > 31 || !Array.isArray(value.posts) || value.posts.length > 100
      || typeof value.posts_truncated !== 'boolean' || typeof value.counts_partial !== 'boolean' || typeof value.timezone !== 'string' || value.timezone.length > 100) throw new UiFailure('Не удалось прочитать календарь.');
    const counts = {};
    for (const [date, count] of Object.entries(value.countsByDate)) { if (!validDay(date) || date.slice(0, 7) !== month || !integer(count, 0)) throw new UiFailure('Не удалось прочитать календарь.'); counts[date] = count; }
    const posts = value.posts.map(post => {
      if (!plain(post) || !['scheduled', 'telegram_pending', 'sent', 'failed'].includes(post.status) || typeof post.text_preview !== 'string' || Array.from(post.text_preview).length > 600 || typeof post.time_label !== 'string' || (post.time_label !== '' && !/^([01][0-9]|2[0-3]):[0-5][0-9]$/.test(post.time_label))) throw new UiFailure('Не удалось прочитать публикации.');
      return { status: post.status, text_preview: post.text_preview, time_label: post.time_label };
    });
    if (value.today !== undefined && !validDay(value.today)) throw new UiFailure('Не удалось прочитать текущую дату.');
    return { month, day, timezone: value.timezone, today: value.today || viewerToday(value.timezone), countsByDate: counts, posts, posts_truncated: value.posts_truncated, counts_partial: value.counts_partial };
  }
  function globalTargetMode(targets) {
    if (!targets.length) return 'copy';
    const modes = new Set(targets.map(target => target.mode)); return modes.size === 1 ? targets[0].mode : null;
  }

  function extractLaunch(href) {
    const url = new URL(href);
    if (url.hash.length > 131072) throw new UiFailure('Некорректная ссылка. Откройте настройки заново из бота.');
    const query = url.searchParams.getAll('launch');
    const fragment = new URLSearchParams(url.hash.slice(1)).getAll('launch');
    if (query.length > 1 || fragment.length > 1) throw new UiFailure('Некорректная ссылка. Откройте настройки заново из бота.');
    const values = query.concat(fragment);
    if (!values.length || values.some(value => !launchPattern.test(value)) || values.some(value => value !== values[0])) throw new UiFailure('Откройте пост в боте и нажмите «Далее».');
    return values[0];
  }

  function endpointFromLocation(href) {
    const url = new URL(href);
    if (url.protocol !== 'https:') throw new UiFailure('Настройки доступны только по защищённой ссылке из бота.');
    url.hash = ''; url.search = ''; url.searchParams.set('telegram_webapp', '1');
    return url.origin + url.pathname + url.search;
  }
  function publicLaunchLocation(href, launch) {
    if (typeof launch !== 'string' || !launchPattern.test(launch)) throw new UiFailure('Некорректный контекст.');
    const url = new URL(endpointFromLocation(href)); url.searchParams.set('launch', launch); return url.href;
  }
  function cleanAuthFragment(location, history, launch) {
    try {
      const url = new URL(location.href);
      if (!new URLSearchParams(url.hash.slice(1)).has('tgWebAppData') || !history || typeof history.replaceState !== 'function') return false;
      history.replaceState(null, '', publicLaunchLocation(location.href, launch)); return true;
    } catch (_) { return false; }
  }

  function randomRequestId(cryptoObject) {
    if (!cryptoObject || typeof cryptoObject.getRandomValues !== 'function') throw new UiFailure('Этот браузер не поддерживает безопасную отправку. Откройте пост в Telegram.');
    const bytes = new Uint8Array(16); cryptoObject.getRandomValues(bytes);
    return Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
  }

  function validatePayload(action, payload) {
    if (!ACTIONS.has(action)) throw new UiFailure('Недопустимое действие.');
    if (['read', 'prepare_publish', 'prepare_schedule'].includes(action)) { if (!exactKeys(payload, [])) throw new UiFailure('Недопустимые параметры.'); return; }
    if (action === 'read_target_metadata') { if (!exactKeys(payload, ['id']) || !integer(payload.id, 1) || payload.id > 4294967295) throw new UiFailure('Недопустимый канал.'); return; }
    if (action === 'read_calendar') { if (!exactKeys(payload, ['month', 'day']) || !validMonth(payload.month) || !validDay(payload.day) || payload.day.slice(0, 7) !== payload.month) throw new UiFailure('Выберите дату календаря.'); return; }
    if (action === 'operation_state') { if (!exactKeys(payload, ['request_id']) || (typeof payload.request_id !== 'string' || !requestIdPattern.test(payload.request_id))) throw new UiFailure('Недопустимая операция.'); return; }
    if (action === 'confirm') { if (!exactKeys(payload, ['token']) || typeof payload.token !== 'string' || payload.token.length < 16 || payload.token.length > 1024 || /[\x00-\x20\x7f]/.test(payload.token)) throw new UiFailure('Подтверждение устарело. Откройте его заново.'); return; }
    if (action === 'set_schedule') { if (!exactKeys(payload, ['local_value']) || typeof payload.local_value !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(payload.local_value)) throw new UiFailure('Укажите дату и время.'); return; }
    if (action === 'set_timer_custom' || action === 'set_repeat_custom') { validateCustomDuration(action === 'set_timer_custom' ? 'timer' : 'repeat', payload); return; }
    if (action === 'set_top') { if (!exactKeys(payload, ['enabled']) || typeof payload.enabled !== 'boolean') throw new UiFailure('Выберите настройку удержания в топе.'); return; }
    if (action === 'set_timer' || action === 'set_repeat') { if (!exactKeys(payload, ['minutes']) || !integer(payload.minutes, 0) || payload.minutes > 525600) throw new UiFailure('Выберите срок из списка.'); return; }
    if (!exactKeys(payload, ['targets']) || !Array.isArray(payload.targets) || payload.targets.length > 40) throw new UiFailure('Можно выбрать не больше 40 каналов.');
    const seen = new Set();
    for (const target of payload.targets) {
      if (!exactKeys(target, ['id', 'mode', 'category_id']) || !integer(target.id, 1) || !integer(target.category_id, 0) || !['copy', 'forward'].includes(target.mode) || seen.has(target.id)) throw new UiFailure('Проверьте выбранные каналы.');
      seen.add(target.id);
    }
  }

  function createTransport(options) {
    if (typeof options.initData !== 'string' || !options.initData.length || options.initData.length > 16384 || (typeof options.launch !== 'string' || !launchPattern.test(options.launch))) throw new UiFailure('Откройте настройки из Telegram.');
    const endpoint = endpointFromLocation(options.endpoint);
    return {
      async send(action, revision, payload, requestId) {
        validatePayload(action, payload);
        if (typeof requestId !== 'string' || !requestIdPattern.test(requestId) || (!['read', 'read_calendar', 'read_target_metadata', 'operation_state'].includes(action) && !validRevision(revision))) throw new UiFailure('Обновите карточку поста.');
        const controller = new options.AbortController();
        const timeout = setTimeout(() => controller.abort(), action === 'confirm' ? 45000 : 15000);
        try {
          const response = await options.fetch(endpoint, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', referrerPolicy: 'no-referrer', signal: controller.signal,
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-GitPosted-WebApp': '1' },
            body: JSON.stringify({ init_data: options.initData, launch: options.launch, action, revision: validRevision(revision) ? revision : null, request_id: requestId, payload })
          });
          const raw = await response.text();
          if (raw.length > 1048576) throw new RequestFailure('Не удалось прочитать ответ. Обновите карточку.', 'invalid_response', action);
          let data; try { data = JSON.parse(raw); } catch (_) { throw new RequestFailure('Не удалось прочитать ответ. Обновите карточку.', 'invalid_response', action); }
          if (!response.ok || !plain(data) || data.ok !== true) {
            const error = plain(data) && plain(data.error) ? data.error : {};
            throw new RequestFailure(text(error.message, 600) || 'Не удалось выполнить действие. Обновите карточку.', text(error.code, 80) || 'request_failed', action, error.reload === true);
          }
          return data;
        } catch (error) {
          if (error instanceof RequestFailure) throw error;
          throw new RequestFailure(action === 'confirm' ? 'Ответ об отправке не получен. Проверяем состояние; повторной отправки не будет.' : 'Ответ не получен. Обновите карточку перед следующим действием.', 'connection_lost', action);
        } finally { clearTimeout(timeout); }
      }
    };
  }

  function normalizeTop(value) {
    if (value === undefined) return { available: false, enabled: false, can_enable: false, can_disable: false, hold: null };
    if (!plain(value) || typeof value.enabled !== 'boolean' || !integer(value.duration_minutes, 1) || value.duration_minutes > 525600
      || !integer(value.revision, 0) || typeof value.can_enable !== 'boolean' || typeof value.can_disable !== 'boolean'
      || typeof value.unlimited !== 'boolean' || (value.unlimited ? value.quota_remaining !== null : !integer(value.quota_remaining, 0))
      || typeof value.reason !== 'string' || value.reason.length > 1500) throw new UiFailure('Не удалось прочитать удержание в топе. Обновите карточку.');
    let hold = null;
    if (value.hold !== null) {
      const item = value.hold;
      if (!plain(item) || !integer(item.post_id, 1) || typeof item.uncertain !== 'boolean'
        || (item.until !== null && !integer(item.until, 1)) || (!item.uncertain && item.until === null)
        || typeof item.until_label !== 'string' || item.until_label.length > 120
        || (item.post_url !== null && (typeof item.post_url !== 'string' || !/^https:\/\/t\.me\/(?:[A-Za-z0-9_]{1,64}|c\/[1-9][0-9]{0,18})\/[1-9][0-9]{0,18}$/.test(item.post_url)))) throw new UiFailure('Не удалось проверить занятость канала. Обновите карточку.');
      hold = { post_id: item.post_id, uncertain: item.uncertain, until: item.until, until_label: item.until_label, post_url: item.post_url };
    }
    return { available: true, enabled: value.enabled, duration_minutes: value.duration_minutes, revision: value.revision, can_enable: value.can_enable,
      can_disable: value.can_disable, quota_remaining: value.quota_remaining, unlimited: value.unlimited, reason: value.reason, hold };
  }

  function normalizeTargetMetadata(value, expectedId) {
    if (!exactKeys(value, ['id', 'subscriber_count']) || value.id !== expectedId || !integer(expectedId, 1) || expectedId > 4294967295
      || (value.subscriber_count !== null && (!integer(value.subscriber_count, 0) || value.subscriber_count > 2147483647))) return null;
    return { id: value.id, subscriber_count: value.subscriber_count };
  }
  function normalizeState(value) {
    if (!plain(value) || !validRevision(value.revision) || !plain(value.post) || !plain(value.capabilities)) throw new UiFailure('Не удалось обновить карточку. Откройте пост заново из бота.');
    function choices(group, kind) {
      if (!plain(group) || !integer(group.selected, 0) || !Array.isArray(group.choices) || group.choices.length > 64) throw new UiFailure('Не удалось прочитать настройки поста.');
      const seen = new Set(); const list = group.choices.map(choice => {
        if (!plain(choice) || !integer(choice.value, 0) || !text(choice.label, 100) || seen.has(choice.value)) throw new UiFailure('Не удалось прочитать настройки поста.');
        seen.add(choice.value); return { value: choice.value, label: text(choice.label, 100), disabled: choice.disabled === true, reason: text(choice.reason, 300) };
      });
      const expectedDisplay = kind === 'timer' && group.selected === 2875 ? 2880 : group.selected;
      if (group.display_minutes !== undefined && group.display_minutes !== expectedDisplay) throw new UiFailure('Не удалось прочитать интервал.');
      const limits = kind === 'timer' ? { days: 60, hours: 23, minutes: 59, total_minutes: MAX_TIMER_CUSTOM_MINUTES } : { days: 365, hours: 23, total_minutes: MAX_REPEAT_CUSTOM_MINUTES, min_positive_minutes: 60 };
      if (group.limits !== undefined && (!exactKeys(group.limits, Object.keys(limits)) || Object.keys(limits).some(key => group.limits[key] !== limits[key]))) throw new UiFailure('Не удалось прочитать ограничения интервала.');
      const components = durationParts(kind, group.selected);
      if (group.custom_components !== undefined && (components === null ? group.custom_components !== null : !exactKeys(group.custom_components, Object.keys(components)) || Object.keys(components).some(key => group.custom_components[key] !== components[key]))) throw new UiFailure('Не удалось прочитать интервал.');
      const mode = group.selected === 0 ? 'off' : group.selected > 2875 ? 'manual' : 'automatic';
      if (kind === 'timer' && group.mode !== undefined && group.mode !== mode) throw new UiFailure('Не удалось прочитать режим удаления.');
      return { selected: group.selected, selected_label: text(group.selected_label, 100), choices: list, display_minutes: expectedDisplay, limits, custom_components: components, mode: kind === 'timer' ? mode : null, warning: text(group.warning, 600) };
    }
    const capabilities = {};
    for (const key of ['can_publish', 'can_schedule', 'can_repeat', 'multipost']) capabilities[key] = value.capabilities[key] === true;
    capabilities.repeat_requires_subscription = value.capabilities.repeat_requires_subscription === true;
    capabilities.multipost_requires_subscription = value.capabilities.multipost_requires_subscription === true;
    capabilities.can_clear_repeat = value.capabilities.can_clear_repeat === undefined ? capabilities.can_repeat : value.capabilities.can_clear_repeat === true;
    for (const key of ['publish_reason', 'schedule_reason', 'repeat_reason', 'multipost_reason']) capabilities[key] = text(value.capabilities[key], 400);
    const targets = plain(value.targets) ? value.targets : { choices: [], selected: [] };
    if (!Array.isArray(targets.choices) || !Array.isArray(targets.selected) || targets.choices.length > 64 || targets.selected.length > 40) throw new UiFailure('Не удалось прочитать список каналов.');
    const ids = new Set(); const available = targets.choices.map(choice => {
      if (!plain(choice) || !integer(choice.id, 1) || ids.has(choice.id) || !Array.isArray(choice.modes) || choice.modes.some(mode => !['copy', 'forward'].includes(mode))) throw new UiFailure('Не удалось прочитать список каналов.');
      ids.add(choice.id); const categories = Array.isArray(choice.categories) ? choice.categories : []; if (categories.length > 64) throw new UiFailure('Не удалось прочитать список компаний.');
      const categoryIds = new Set(); const safeCategories = categories.map(category => {
        if (!plain(category) || !integer(category.id, 0) || categoryIds.has(category.id)) throw new UiFailure('Не удалось прочитать список компаний.');
        categoryIds.add(category.id); return { id: category.id, title: text(category.title, 160), disabled: category.disabled === true, reason: text(category.reason, 300) };
      });
      return { id: choice.id, title: text(choice.title, 200), subscriber_count: integer(choice.subscriber_count, 0) && choice.subscriber_count <= 2147483647 ? choice.subscriber_count : null, modes: Array.from(new Set(choice.modes)), categories: safeCategories, disabled: choice.disabled === true, reason: text(choice.reason, 400) };
    });
    const selectedIds = new Set(); const selected = targets.selected.map(target => {
      if (!plain(target) || !integer(target.id, 1) || (target.category_id !== null && !integer(target.category_id, 0)) || !['copy', 'forward'].includes(target.mode) || selectedIds.has(target.id)) throw new UiFailure('Не удалось прочитать выбранные каналы.');
      selectedIds.add(target.id); return { id: target.id, mode: target.mode, category_id: target.category_id };
    });
    let operation = null;
    if (value.operation !== null && value.operation !== undefined) {
      if (!plain(value.operation) || !OPERATION_STATUSES.has(value.operation.status)) throw new UiFailure('Состояние отправки требует проверки в боте.');
      operation = { status: value.operation.status, message: text(value.operation.message, 700), request_id: typeof value.operation.request_id === 'string' && requestIdPattern.test(value.operation.request_id) ? value.operation.request_id : null };
    }
    const schedule = plain(value.schedule) ? value.schedule : {};
    const localValue = typeof schedule.local_value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(schedule.local_value) ? schedule.local_value : '';
    const preview = typeof value.post.preview_text === 'string' ? Array.from(value.post.preview_text) : null;
    return { revision: value.revision, post: { title: text(value.post.title, 1000), channel_title: text(value.post.channel_title, 200), status: text(value.post.status, 40), preview_text: preview === null ? text(value.post.title, 1000) : preview.slice(0, 32768).join(''), preview_truncated: value.post.preview_truncated === true || (preview !== null && preview.length > 32768), has_media: value.post.has_media === true, forward_allowed: value.post.forward_allowed === undefined ? null : value.post.forward_allowed === true, author_label: text(value.post.author_label, 200), time_label: text(value.post.time_label, 40) }, capabilities, top: normalizeTop(value.top), timer: choices(value.timer, 'timer'), repeat: choices(value.repeat, 'repeat'), schedule: { local_value: localValue, timezone: text(schedule.timezone, 100), today: validDay(schedule.today) ? schedule.today : viewerToday(text(schedule.timezone, 100)) }, targets: { choices: available, selected, metadata_available: targets.metadata_available === true }, operation };
  }

  const helpers = { safeColor, extractLaunch, endpointFromLocation, publicLaunchLocation, cleanAuthFragment, randomRequestId, validatePayload, createTransport, normalizeState, normalizeTargetMetadata, normalizeTop, customDurationPayload, validateCustomDuration, durationPresets, durationParts, exactDurationLabel, validDay, validMonth, shiftedMonth, calendarCells, viewerToday, scheduleValue, normalizeCalendar, globalTargetMode, RequestFailure, UiFailure, userMessage };
  if (typeof module === 'object' && module.exports) module.exports = helpers;
  if (typeof document === 'undefined') return;

  function start() {
    const byId = id => document.getElementById(id);
    const nodes = {}; for (const id of ['top-section', 'top-toggle', 'top-description', 'top-quota', 'top-reason', 'top-block', 'top-block-message', 'top-block-link', 'top-refresh', 'entry-message', 'entry-title', 'entry-description', 'editor', 'feedback', 'repeat-reason', 'schedule-timezone', 'calendar-previous', 'calendar-next', 'calendar-month', 'calendar-days', 'calendar-status', 'calendar-posts', 'calendar-posts-heading', 'calendar-retry', 'schedule-hour', 'schedule-minute', 'schedule-hour-up', 'schedule-hour-down', 'schedule-minute-up', 'schedule-minute-down', 'targets-description', 'targets-summary', 'selected-targets', 'target-choices', 'operation-panel', 'operation-heading', 'operation-message', 'check-operation', 'action-reason', 'publish-post', 'reload-state', 'confirmation-dialog', 'confirmation-summary', 'confirmation-expired', 'cancel-confirmation', 'confirm-operation', 'confirmation-illustration', 'confirmation-question', 'confirmation-channel', 'confirmation-footer', 'preview-text', 'preview-time', 'preview-author', 'preview-truncated', 'preview-media', 'timer-presets', 'timer-custom', 'timer-current', 'timer-warning', 'timer-days', 'timer-hours', 'timer-minutes', 'timer-presets-back', 'repeat-presets', 'repeat-custom', 'repeat-current', 'repeat-warning', 'repeat-days', 'repeat-hours', 'repeat-presets-back', 'initial-step', 'date-step', 'date-back', 'targets-step', 'targets-back', 'picker-mode', 'picker-count', 'select-all-targets', 'mode-copy', 'mode-forward', 'add-targets', 'main-footer', 'next-step', 'publication-time-dialog', 'postpone-post', 'cancel-publication-time', 'timer-help', 'repeat-help', 'targets-help', 'help-dialog', 'help-heading', 'help-message', 'help-illustration', 'help-safety', 'help-close-x', 'close-help']) nodes[id] = byId(id);
    const sdk = root.Telegram && root.Telegram.WebApp;
    let metadataGeneration = 0; let metadataActive = 0; let metadataQueue = [];
    let state = null; let transport = null; let busy = false; let uncertainOperation = null; let confirmation = null; let confirmationTimer = null; let pollTimer = null; let pollsRemaining = 0;
    const targetControls = new Map();
    const durations = {};
    let step = 'initial'; let picker = null; let stagedMode = null; let flushingDurations = false;
    let calendar = null; let calendarSequence = 0; let calendarDayButtons = []; let sentCloseAttempted = false;
    const nativeMain = sdk && sdk.MainButton && typeof sdk.MainButton.setParams === 'function' && typeof sdk.MainButton.onClick === 'function' && typeof sdk.MainButton.hide === 'function' ? sdk.MainButton : null;
    let nativeMainUsable = Boolean(nativeMain);
    const nativeBack = sdk && sdk.BackButton && typeof sdk.BackButton.onClick === 'function' && typeof sdk.BackButton.show === 'function' && typeof sdk.BackButton.hide === 'function' ? sdk.BackButton : null;
    let nativeBackUsable = Boolean(nativeBack);
    function feedback(message, error) { nodes.feedback.textContent = message; nodes.feedback.dataset.error = error ? 'true' : 'false'; nodes.feedback.hidden = !message; }
    function unavailable(message) { nodes.editor.hidden = true; nodes['entry-message'].hidden = false; nodes['entry-title'].textContent = 'Откройте настройки из бота'; nodes['entry-description'].textContent = message; if (nativeMain) { try { nativeMain.hide(); } catch (_) {} } }
    function applyTheme() {
      const theme = sdk && plain(sdk.themeParams) ? sdk.themeParams : {};
      if (!safeColor(theme.bg_color) || !safeColor(theme.text_color)) return;
      const pairs = { '--app-bg': 'bg_color', '--app-panel': 'secondary_bg_color', '--app-text': 'text_color', '--app-muted': 'hint_color' };
      for (const name of Object.keys(pairs)) { const color = safeColor(theme[pairs[name]]); if (color) document.documentElement.style.setProperty(name, color); }
      const color = safeColor(theme.bg_color);
      if (color) { const panel = safeColor(theme.secondary_bg_color) || color; try { if (typeof sdk.setBackgroundColor === 'function') sdk.setBackgroundColor(panel); } catch (_) {} try { if (typeof sdk.setHeaderColor === 'function') sdk.setHeaderColor(panel); } catch (_) {} }
    }
    function pending() { return Boolean(uncertainOperation || (state && state.operation && PENDING_STATUSES.has(state.operation.status))); }
    function disabled(value) {
      busy = value; document.body.dataset.busy = value ? 'true' : 'false';
      if (!state) return;
      const blocked = value || flushingDurations || pending() || !['draft', 'failed', 'scheduled'].includes(state.post.status);
      const calendarLocked = blocked || state.post.status !== 'draft' || !state.capabilities.can_schedule;
      for (const id of ['schedule-hour', 'schedule-minute', 'schedule-hour-up', 'schedule-hour-down', 'schedule-minute-up', 'schedule-minute-down', 'calendar-retry']) nodes[id].disabled = calendarLocked;
      nodes['calendar-previous'].disabled = calendarLocked || !calendar || !shiftedMonth(calendar.month, -1); nodes['calendar-next'].disabled = calendarLocked || !calendar || !shiftedMonth(calendar.month, 1);
      for (const button of calendarDayButtons) button.disabled = calendarLocked || button.dataset.past === 'true';
      nodes['publish-post'].disabled = blocked || !state.capabilities.can_publish;
      nodes['postpone-post'].disabled = blocked || !state.capabilities.can_schedule;
      nodes['top-toggle'].disabled = blocked || state.post.status !== 'draft' || !state.top.available || !(state.top.enabled ? state.top.can_disable : state.top.can_enable);
      nodes['top-refresh'].disabled = value || pending();
      const targetLocked = blocked || state.post.status !== 'draft' || !state.capabilities.multipost;
      nodes['add-targets'].disabled = targetLocked; nodes['mode-copy'].disabled = targetLocked; nodes['mode-forward'].disabled = targetLocked; nodes['select-all-targets'].disabled = targetLocked;
      if (state.capabilities.multipost_requires_subscription && !blocked && state.post.status === 'draft') nodes['add-targets'].disabled = false;
      if (state.post.forward_allowed === false || state.targets.choices.length && !state.targets.choices.some(choice => choice.modes.includes('forward'))) nodes['mode-forward'].disabled = true;
      for (const control of targetControls.values()) { control.checkbox.disabled = targetLocked || (control.unavailable && !control.checkbox.checked); if (control.category) control.category.disabled = targetLocked || !control.checkbox.checked; }
      for (const kind of ['timer', 'repeat']) {
        const control = durations[kind]; if (!control) continue;
        const locked = blocked || state.post.status !== 'draft' || (kind === 'repeat' && !state.capabilities.can_repeat && !state.capabilities.repeat_requires_subscription && !state.capabilities.can_clear_repeat);
        for (const button of control.buttons) button.disabled = locked || button.dataset.unavailable === 'true' || (kind === 'repeat' && (button.dataset.value === '0' ? !state.capabilities.can_clear_repeat : !state.capabilities.can_repeat && !state.capabilities.repeat_requires_subscription));
        const fieldsLocked = locked || kind === 'repeat' && !state.capabilities.can_repeat;
        for (const input of Object.values(control.fields)) input.disabled = fieldsLocked;
        for (const button of control.steppers) button.disabled = fieldsLocked;
        nodes[kind + '-presets-back'].disabled = locked;
      }
      nodes['reload-state'].disabled = value; nodes['check-operation'].disabled = value;
      updateMainButton();
    }
    function showStep(next) {
      if (step === 'date' && next !== 'date') calendarSequence += 1;
      step = next; nodes['initial-step'].hidden = next !== 'initial'; nodes['date-step'].hidden = next !== 'date'; nodes['targets-step'].hidden = next !== 'targets'; updateMainButton();
    }
    function renderCalendar() {
      if (!calendar) return;
      const [year, month] = calendar.month.split('-').map(Number);
      nodes['calendar-month'].textContent = new Intl.DateTimeFormat('ru-RU', { month: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(year, month - 1, 1))) + ', ' + year;
      const cells = []; calendarDayButtons = [];
      for (const [index, day] of calendarCells(calendar.month).entries()) {
        if (!day) { const blank = document.createElement('span'); blank.setAttribute('aria-hidden', 'true'); cells.push(blank); continue; }
        const button = document.createElement('button'); button.type = 'button'; button.className = 'calendar-day'; button.dataset.day = day; button.dataset.weekend = index % 7 > 4 ? 'true' : 'false'; button.dataset.past = calendar.today && day < calendar.today ? 'true' : 'false'; button.dataset.today = day === calendar.today ? 'true' : 'false'; button.setAttribute('aria-pressed', day === calendar.day ? 'true' : 'false'); button.setAttribute('aria-label', day);
        const number = document.createElement('span'); number.textContent = String(Number(day.slice(8))); button.append(number);
        if (calendar.data && Object.prototype.hasOwnProperty.call(calendar.data.countsByDate, day) && calendar.data.countsByDate[day] > 0) { const count = document.createElement('small'); count.className = 'calendar-count'; count.textContent = String(calendar.data.countsByDate[day]) + (calendar.data.counts_partial ? '+' : ''); button.append(count); }
        button.addEventListener('click', () => { if (button.disabled || busy || pending() || !state.capabilities.can_schedule || state.post.status !== 'draft' || (calendar.today && day < calendar.today)) return; calendar.day = day; feedback('', false); return loadCalendar(); });
        calendarDayButtons.push(button); cells.push(button);
      }
      nodes['calendar-days'].replaceChildren(...cells);
      let heading = 'Посты на ' + calendar.day.split('-').reverse().join('.');
      if (calendar.day === calendar.today) heading = 'Сегодня';
      else if (validDay(calendar.today)) { const tomorrow = new Date(calendar.today + 'T00:00:00Z'); tomorrow.setUTCDate(tomorrow.getUTCDate() + 1); if (calendar.day === tomorrow.toISOString().slice(0, 10)) heading = 'Завтра'; }
      nodes['calendar-posts-heading'].textContent = heading;
      nodes['schedule-timezone'].textContent = calendar.timezone ? 'Часовой пояс: ' + calendar.timezone : '';
      nodes['calendar-retry'].hidden = !calendar.error;
      const publications = [];
      if (calendar.loading) nodes['calendar-status'].textContent = 'Загружаем публикации…';
      else if (calendar.error) nodes['calendar-status'].textContent = 'Не удалось загрузить публикации. Повторите попытку.';
      else if (calendar.data) {
        const data = calendar.data;
        nodes['calendar-status'].textContent = data.counts_partial || data.posts_truncated ? 'Показана часть публикаций. Полный список доступен в контент-плане бота.' : '';
        if (!data.posts.length && !data.counts_partial && !data.posts_truncated) {
          const empty = document.createElement('div'); empty.className = 'calendar-empty'; const illustration = document.createElement('span'); illustration.setAttribute('aria-hidden', 'true'); illustration.textContent = '📭'; const label = document.createElement('p'); label.textContent = 'Посты за данный период не найдены'; empty.append(illustration, label); publications.push(empty);
        }
        for (const post of data.posts) {
          const item = document.createElement('article'); item.className = 'calendar-post'; const meta = document.createElement('div'); meta.className = 'calendar-post-meta'; const time = document.createElement('strong'); time.textContent = post.time_label; const status = document.createElement('span'); status.textContent = { scheduled: 'Отложен', telegram_pending: 'Проверяем отправку', sent: 'Опубликован', failed: 'Ошибка отправки' }[post.status]; meta.append(time, status); const preview = document.createElement('p'); preview.textContent = post.text_preview || 'Публикация без текста'; item.append(meta, preview); publications.push(item);
        }
      } else nodes['calendar-status'].textContent = 'Публикации ещё не загружены.';
      nodes['calendar-posts'].replaceChildren(...publications); disabled(busy);
    }
    async function loadCalendar() {
      if (!calendar || step !== 'date') return;
      const current = calendar, month = current.month, day = current.day, sequence = ++calendarSequence;
      current.loading = true; current.error = false; current.data = null; renderCalendar();
      try {
        const reply = await send('read_calendar', { month, day });
        const data = normalizeCalendar(reply.calendar, month, day);
        if (sequence !== calendarSequence || calendar !== current || step !== 'date') return;
        current.loading = false; current.data = data; current.today = data.today || current.today; current.timezone = data.timezone; renderCalendar();
      } catch (_) {
        if (sequence !== calendarSequence || calendar !== current || step !== 'date') return;
        current.loading = false; current.error = true; renderCalendar();
      }
    }
    function openCalendar() {
      if (!state || busy || pending() || !state.capabilities.can_schedule || state.post.status !== 'draft') return;
      const today = state.schedule.today || viewerToday(state.schedule.timezone);
      const savedDay = state.schedule.local_value.slice(0, 10), day = validDay(savedDay) ? savedDay : today;
      if (!validDay(day)) { feedback('Не удалось определить текущую дату. Обновите карточку.', true); return; }
      calendar = { month: day.slice(0, 7), day, today, timezone: state.schedule.timezone, data: null, loading: false, error: false };
      nodes['schedule-hour'].value = state.schedule.local_value ? state.schedule.local_value.slice(11, 13) : ''; nodes['schedule-minute'].value = state.schedule.local_value ? state.schedule.local_value.slice(14, 16) : '';
      feedback('', false); showStep('date'); return loadCalendar();
    }
    function navigateCalendar(offset) {
      if (!calendar || busy || pending() || !state.capabilities.can_schedule || state.post.status !== 'draft') return;
      const month = shiftedMonth(calendar.month, offset); if (!month) return;
      calendar.month = month; calendar.day = calendar.today && calendar.today.slice(0, 7) === month ? calendar.today : month + '-01'; feedback('', false); return loadCalendar();
    }
    function focusHeading(id) { const heading = document.getElementById(id); if (heading && typeof heading.focus === 'function') heading.focus({ preventScroll: true }); }
    function updateMainButton() {
      if (!state) return;
      const helping = nodes['help-dialog'].open, confirming = nodes['confirmation-dialog'].open;
      const knownSent = Boolean(state.operation && state.operation.status === 'sent');
      const label = helping ? 'Понятно' : confirming ? confirmation && confirmation.kind === 'schedule' ? 'Да, запланировать' : 'Да, опубликовать' : knownSent ? 'Готово' : step === 'targets' ? 'Выбрать' : step === 'date' ? 'Отложить' : 'Далее';
      const locked = helping ? false : confirming ? busy || pending() || !confirmation || confirmation.expires_at * 1000 <= Date.now() : knownSent ? busy : busy || flushingDurations || pending() || nodes['publication-time-dialog'].open || state.post.status !== 'draft' || (step === 'targets' ? !state.capabilities.multipost : step === 'date' ? !state.capabilities.can_schedule : !state.capabilities.can_publish && !state.capabilities.can_schedule);
      nodes['next-step'].textContent = label; nodes['next-step'].disabled = locked;
      if (nativeMainUsable) { try { nativeMain.setParams({ text: label, color: '#ee9e00', text_color: '#ffffff', is_active: !locked, is_visible: !nodes.editor.hidden }); } catch (_) { try { nativeMain.hide(); } catch (_) {} nativeMainUsable = false; } }
      document.body.dataset.nativeMain = nativeMainUsable ? 'true' : 'false'; nodes['main-footer'].hidden = nativeMainUsable || helping || confirming;
      nodes['close-help'].hidden = nativeMainUsable; nodes['confirmation-footer'].hidden = nativeMainUsable;
      nodes['confirm-operation'].disabled = !confirmation || busy || pending() || confirmation.expires_at * 1000 <= Date.now();
      if (nativeBackUsable) { try { if (confirming || step !== 'initial') nativeBack.show(); else nativeBack.hide(); } catch (_) { nativeBackUsable = false; } }
      nodes['cancel-confirmation'].hidden = nativeBackUsable; nodes['date-back'].hidden = nativeBackUsable; nodes['targets-back'].hidden = nativeBackUsable;
    }
    function timerIcon() {
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      for (const [name, value] of Object.entries({ viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': '1.5', 'aria-hidden': 'true' })) svg.setAttribute(name, value);
      const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle'); circle.setAttribute('cx', '12'); circle.setAttribute('cy', '14'); circle.setAttribute('r', '7');
      const path = document.createElementNS('http://www.w3.org/2000/svg', 'path'); path.setAttribute('d', 'M12 10v4m-3-11h6m-3 0v3m5 2 2-2'); svg.append(circle, path); return svg;
    }
    function chevronIcon(up) {
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('aria-hidden', 'true');
      const path = document.createElementNS('http://www.w3.org/2000/svg', 'path'); path.setAttribute('d', up ? 'M6 15l6-6 6 6' : 'M6 9l6 6 6-6'); path.setAttribute('fill', 'none'); path.setAttribute('stroke', 'currentColor'); path.setAttribute('stroke-width', '3'); path.setAttribute('stroke-linecap', 'round'); path.setAttribute('stroke-linejoin', 'round'); svg.append(path); return svg;
    }
    // Independent code-native illustrations. Only fixed shapes and local SVG
    // gradients are used; no downloaded emoji, external references or raw HTML.
    function illustration(kind) {
      if (kind === 'timer') { const sphere = document.createElement('span'); sphere.className = 'timer-sphere'; sphere.setAttribute('aria-hidden', 'true'); return sphere; }
      function shape(name, attributes) { const node = document.createElementNS('http://www.w3.org/2000/svg', name); for (const [key, value] of Object.entries(attributes)) node.setAttribute(key, String(value)); return node; }
      const svg = shape('svg', { viewBox: '0 0 100 100', width: 80, height: 80, 'aria-hidden': 'true', focusable: 'false' }); svg.dataset.illustration = kind;
      const defs = shape('defs', {}); svg.append(defs);
      function gradient(name, stops) { const id = 'gp-' + kind + '-' + name, gradient = shape('linearGradient', { id, x1: '15%', y1: '0%', x2: '80%', y2: '100%' }); for (const [offset, color] of stops) gradient.append(shape('stop', { offset, 'stop-color': color })); defs.append(gradient); return 'url(#' + id + ')'; }
      if (kind === 'duck' || kind === 'premium') {
        const yellow = gradient('yellow', [['0%', '#fff478'], ['48%', '#ffe22a'], ['100%', '#ffc600']]);
        const orange = gradient('beak', [['0%', '#ffad19'], ['100%', '#ed7412']]);
        const duck = shape('g', { class: 'rubber-duck-motion', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' });
        if (kind === 'duck') svg.append(shape('ellipse', { cx: 50, cy: 88, rx: 34, ry: 5, fill: 'none', stroke: '#3298c7', 'stroke-width': 1.5, opacity: .7 }));
        duck.append(shape('path', { d: 'M25 59C13 49 15 28 28 16C40 4 61 5 74 18C87 31 86 48 75 59C82 66 85 79 75 85C65 91 33 91 23 84C15 79 18 67 25 59Z', fill: yellow, stroke: '#f6ac00', 'stroke-width': 2 }));
        duck.append(shape('path', { d: 'M27 29C29 19 38 13 47 13M57 13C63 14 68 17 71 21', fill: 'none', stroke: '#fffbd0', 'stroke-width': 2.5, opacity: .95 }));
        duck.append(shape('path', { d: 'M28 69C27 79 32 83 37 84C41 82 42 72 40 67M34 71L36 81', fill: 'none', stroke: '#f3a800', 'stroke-width': 1.8 }));
        if (kind === 'premium') {
          duck.append(shape('path', { d: 'M33 39Q37 42 41 38M60 39Q64 42 68 38', fill: 'none', stroke: '#5b3600', 'stroke-width': 2 }));
        } else {
          for (const cx of [36, 65]) { duck.append(shape('ellipse', { cx, cy: 38, rx: 4.2, ry: 5, fill: '#302000' })); duck.append(shape('circle', { cx: cx - 1.2, cy: 36.2, r: 1.5, fill: '#fff' })); }
        }
        duck.append(shape('path', { d: 'M38 48C42 47 46 43 50 43C55 43 59 47 64 48C62 56 41 57 36 51Z', fill: orange, stroke: '#df7209', 'stroke-width': 1.2 }));
        duck.append(shape('path', { d: 'M40 50Q50 54 61 50', fill: 'none', stroke: '#b84606', 'stroke-width': 1.3 })); svg.append(duck);
        if (kind === 'premium') { svg.append(shape('circle', { cx: 50, cy: 57, r: 34, fill: 'none', stroke: '#ed160b', 'stroke-width': 8 })); svg.append(shape('path', { d: 'M21 75L80 40', fill: 'none', stroke: '#ed160b', 'stroke-width': 9, 'stroke-linecap': 'round' })); }
        return svg;
      }
      const steel = gradient('steel', [['0%', '#e1e8ec'], ['45%', '#a6b5bf'], ['100%', '#5c6b75']]);
      const paper = gradient('paper', [['0%', '#fffff2'], ['100%', '#d9d8c8']]);
      const front = gradient('front', [['0%', '#e0e5e9'], ['100%', '#89959e']]);
      if (kind === 'repeat') {
        svg.append(shape('rect', { x: 28, y: 13, width: 45, height: 34, rx: 2, fill: paper, stroke: '#9d9e95', 'stroke-width': 1.5 }));
        svg.append(shape('path', { d: 'M21 40H79Q85 40 87 51L89 79H12L14 51Q15 40 21 40Z', fill: steel, stroke: '#71808a', 'stroke-width': 1.5 }));
        svg.append(shape('rect', { x: 26, y: 53, width: 49, height: 24, rx: 3, fill: '#44525b' })); svg.append(shape('path', { d: 'M29 69H71L75 91H24Z', fill: front, stroke: '#677780', 'stroke-width': 1.5 }));
        svg.append(shape('path', { d: 'M22 43L19 74M80 44L83 74', stroke: '#e9f0f4', 'stroke-width': 3, opacity: .65 })); svg.append(shape('circle', { cx: 80, cy: 53, r: 2.4, fill: '#86cf87' }));
      } else if (kind === 'targets') {
        svg.append(shape('path', { d: 'M19 23L70 9L73 73L20 88Z', fill: paper, stroke: '#9c9e99', 'stroke-width': 1.5 })); svg.append(shape('path', { d: 'M24 27L76 13L78 75L25 90Z', fill: paper, stroke: '#b0b0a7', 'stroke-width': 1.5 }));
        for (const x of [33, 44, 55, 66]) svg.append(shape('path', { d: 'M' + x + ' 37v24m-3-17h6m-6 8h6', fill: 'none', stroke: '#9d9f94', 'stroke-width': 1.4 }));
        svg.append(shape('path', { d: 'M18 88L23 65Q24 57 32 55L87 43L76 80Z', fill: front, stroke: '#a1aab0', 'stroke-width': 1.5 })); svg.append(shape('path', { d: 'M29 60L80 48', stroke: '#f4f6f7', 'stroke-width': 2, opacity: .8 }));
      } else {
        svg.append(shape('path', { d: 'M28 29H73L69 86Q50 92 32 86Z', fill: steel, stroke: '#7c8a93', 'stroke-width': 1.5 })); svg.append(shape('path', { d: 'M24 27H77M39 20H62M40 36L42 79M51 36V81M62 36L60 79', fill: 'none', stroke: '#d5dfe4', 'stroke-width': 4, 'stroke-linecap': 'round' }));
      }
      return svg;
    }
    function createDuration(kind) {
      const fieldNames = kind === 'timer' ? ['days', 'hours', 'minutes'] : ['days', 'hours'];
      const control = { open: false, baseline: '', submitted: null, fields: {}, buttons: [], steppers: [] };
      for (const name of fieldNames) control.fields[name] = nodes[kind + '-' + name];
      function values() { const value = {}; for (const name of fieldNames) value[name] = control.fields[name].value; return value; }
      function fingerprint() { return JSON.stringify(values()); }
      function plannedEdit() {
        if (!control.open || !state) return null;
        const key = fingerprint(); if (key === control.baseline || key === control.submitted) return null;
        if (Object.values(control.fields).some(input => input.validity && input.validity.valid === false)) throw new UiFailure('Введите целые значения в допустимых пределах.');
        const raw = values(), payload = customDurationPayload(kind, raw), total = validateCustomDuration(kind, payload);
        const effective = kind === 'timer' && total === 2880 ? 2875 : total;
        if (effective === state[kind].selected) { control.baseline = key; return null; }
        return { kind, key, raw, payload, total, effective };
      }
      control.pendingEdit = plannedEdit;
      control.restoreDraft = edit => { control.open = true; control.render(state[kind]); for (const name of fieldNames) control.fields[name].value = edit.raw[name]; };
      async function commit() {
        if (busy || flushingDurations || pending() || !state || state.post.status !== 'draft') return false;
        let edit; try { edit = plannedEdit(); } catch (error) { feedback(userMessage(error), true); return false; }
        if (!edit) return true; if (!canApplyDuration(edit)) return false;
        control.submitted = edit.key;
        try { const ok = await mutate(kind === 'timer' ? 'set_timer_custom' : 'set_repeat_custom', edit.payload); if (ok && kind === 'timer' && edit.effective > 2875) showHelp('manual'); return ok; }
        finally { control.submitted = null; }
      }
      for (const input of Object.values(control.fields)) {
        input.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); return commit(); } });
      }
      for (const name of fieldNames) for (const delta of [1, -1]) {
        const button = document.createElement('button'); button.type = 'button'; button.className = delta > 0 ? 'custom-step-up' : 'custom-step-down'; button.setAttribute('aria-label', (delta > 0 ? 'Увеличить: ' : 'Уменьшить: ') + (name === 'days' ? 'дни' : name === 'hours' ? 'часы' : 'минуты')); button.append(chevronIcon(delta > 0));
        button.addEventListener('click', () => { if (button.disabled || busy || pending() || !state || state.post.status !== 'draft') return; const input = control.fields[name], cap = name === 'days' ? kind === 'timer' ? 60 : 365 : name === 'hours' ? 23 : 59; const value = /^[0-9]{0,3}$/.test(input.value) ? Number(input.value) : 0; const next = Math.max(0, Math.min(cap, value + delta)); input.value = next === 0 ? '' : String(next); });
        control.fields[name].parentElement.append(button); control.steppers.push(button);
      }
      nodes[kind + '-custom'].addEventListener('focusout', event => {
        if (event.relatedTarget && (nodes[kind + '-custom'].contains(event.relatedTarget) || event.relatedTarget === nodes[kind + '-presets-back'])) return;
        return commit();
      });
      nodes[kind + '-presets-back'].addEventListener('click', () => {
        if (!state || busy || pending() || nodes[kind + '-presets-back'].disabled) return;
        control.open = false; control.render(state[kind]); disabled(busy);
      });
      control.render = group => {
        const presets = durationPresets(kind); control.buttons = []; const row = nodes[kind + '-presets']; row.replaceChildren();
        for (const preset of presets) {
          const offered = group.choices.find(choice => choice.value === preset.value); const button = document.createElement('button');
          button.type = 'button'; button.className = 'duration-preset'; button.textContent = preset.label; button.dataset.value = String(preset.value); button.dataset.unavailable = !offered || offered.disabled ? 'true' : 'false';
          button.setAttribute('aria-pressed', String(group.selected === preset.value));
          if (kind === 'timer' && preset.value === 4320) button.setAttribute('aria-describedby', 'timer-warning');
          if (kind === 'timer' && preset.value === 2875) button.setAttribute('title', 'Фактически 47 ч 55 мин');
          button.addEventListener('click', async () => { if (control.open || button.disabled || !offered || offered.disabled || busy || pending() || group.selected === preset.value) return; if (kind === 'repeat' && preset.value > 0 && !state.capabilities.can_repeat) { if (state.capabilities.repeat_requires_subscription) showHelp('premium'); return; } const ok = await mutate(kind === 'timer' ? 'set_timer' : 'set_repeat', { minutes: preset.value }); if (ok && kind === 'timer' && preset.value > 2875) showHelp('manual'); });
          row.append(button); control.buttons.push(button);
        }
        const plus = document.createElement('button'); plus.type = 'button'; plus.className = 'duration-preset duration-plus'; plus.append(timerIcon());
        plus.setAttribute('aria-label', kind === 'timer' ? 'Свой таймер удаления' : 'Свой интервал повтора'); plus.setAttribute('aria-controls', kind + '-custom'); plus.setAttribute('aria-expanded', String(control.open));
        plus.addEventListener('click', () => { if (control.open || plus.disabled || busy || pending()) return; if (kind === 'repeat' && !state.capabilities.can_repeat) { if (state.capabilities.repeat_requires_subscription) showHelp('premium'); return; } control.open = true; control.render(state[kind]); disabled(busy); });
        row.append(plus); control.buttons.push(plus);
        nodes[kind + '-custom'].hidden = !control.open;
        nodes[kind + '-presets-back'].hidden = !control.open;
        row.hidden = control.open;
        const parts = group.custom_components;
        for (const name of fieldNames) control.fields[name].value = parts === null || parts[name] === 0 ? '' : String(parts[name]);
        control.baseline = fingerprint();
        const selectedPreset = presets.some(preset => preset.value === group.selected);
        const label = group.selected_label || exactDurationLabel(group.selected); const current = nodes[kind + '-current'];
        current.textContent = 'Текущее значение: ' + label; current.hidden = selectedPreset;
        const warning = nodes[kind + '-warning'];
        if (kind === 'timer') { warning.textContent = 'Требуется ручное удаление.'; warning.hidden = group.mode !== 'manual'; }
        else { warning.textContent = parts === null ? 'Сохранённый интервал: ' + label + '. Новый интервал задаётся целыми часами.' : ''; warning.hidden = parts !== null; }
      };
      return control;
    }
    durations.timer = createDuration('timer'); durations.repeat = createDuration('repeat');
    function canApplyDuration(edit) {
      if (!state || state.post.status !== 'draft' || pending()) { feedback('Карточка изменилась. Обновите настройки.', true); return false; }
      if (edit.kind === 'repeat' && (edit.total > 0 ? !state.capabilities.can_repeat : !state.capabilities.can_clear_repeat)) { if (state.capabilities.repeat_requires_subscription) showHelp('premium'); else feedback(state.capabilities.repeat_reason || 'Изменение повтора недоступно.', true); return false; }
      return true;
    }
    async function flushCustomDurations() {
      let edits;
      try { edits = ['timer', 'repeat'].map(kind => durations[kind].pendingEdit()).filter(Boolean); }
      catch (error) { feedback(userMessage(error), true); return false; }
      if (!edits.length) return true;
      for (const edit of edits) if (!canApplyDuration(edit)) return false;
      flushingDurations = true; disabled(false); let manual = false;
      try {
        for (let i = 0; i < edits.length; i += 1) {
          const edit = edits[i];
          if (!canApplyDuration(edit)) { for (const rest of edits.slice(i)) durations[rest.kind].restoreDraft(rest); return false; }
          const ok = await mutate(edit.kind === 'timer' ? 'set_timer_custom' : 'set_repeat_custom', edit.payload);
          if (!ok) { for (const rest of edits.slice(i + 1)) durations[rest.kind].restoreDraft(rest); return false; }
          if (edit.kind === 'timer' && edit.effective > 2875) manual = true;
        }
      } finally { flushingDurations = false; disabled(false); }
      if (manual) { showHelp('manual'); return false; }
      return true;
    }
    function chosenMode() { return stagedMode === null ? globalTargetMode(state.targets.selected) : stagedMode; }
    function modePending() { return stagedMode !== null && state.targets.selected.length > 0 && stagedMode !== globalTargetMode(state.targets.selected); }
    function setTargetCount(label, count) {
      const known = integer(count, 0) && count <= 2147483647;
      const suffix = known && count % 100 >= 11 && count % 100 <= 14 ? 'подписчиков' : known && count % 10 === 1 ? 'подписчик' : known && count % 10 >= 2 && count % 10 <= 4 ? 'подписчика' : 'подписчиков';
      label.textContent = known ? count + ' ' + suffix : ''; label.hidden = !known;
    }
    function pumpPickerMetadata() {
      while (metadataActive < 2 && metadataQueue.length) {
        const job = metadataQueue.shift(), { generation, basis, panel, choice } = job;
        if (generation !== metadataGeneration || state !== basis || picker !== panel || !state.targets.choices.includes(choice) || choice.subscriber_count !== null) continue;
        metadataActive++;
        async function run() {
          try {
            const reply = await transport.send('read_target_metadata', null, { id: choice.id }, newId());
            const metadata = normalizeTargetMetadata(reply.target_metadata, choice.id);
            if (!metadata || generation !== metadataGeneration || state !== basis || picker !== panel || !state.targets.choices.includes(choice)) return;
            choice.subscriber_count = metadata.subscriber_count;
            const control = targetControls.get(choice.id);
            if (control && control.choice === choice) setTargetCount(control.countLabel, metadata.subscriber_count);
          } catch (_) { /* An optional read never changes selection, capabilities or publication state. */ }
          finally { metadataActive--; pumpPickerMetadata(); }
        }
        void run();
      }
    }
    function loadPickerMetadata() {
      if (!state || !transport || !picker || !state.targets.metadata_available) return;
      const generation = ++metadataGeneration, basis = state, panel = picker;
      metadataQueue = state.targets.choices.filter(choice => choice.subscriber_count === null).map(choice => ({ generation, basis, panel, choice }));
      pumpPickerMetadata();
    }
    function targetPresentation(title, count) {
      const avatar = document.createElement('span'); avatar.className = 'target-avatar'; avatar.setAttribute('aria-hidden', 'true');
      avatar.textContent = title.trim().split(/[.\s_-]+/u).filter(Boolean).slice(0, 2).map(word => Array.from(word)[0]).join('').toUpperCase();
      const content = document.createElement('span'); content.className = 'target-content';
      const name = document.createElement('span'); name.className = 'target-name'; name.textContent = title; content.append(name);
      const label = document.createElement('span'); label.className = 'target-subscribers';
      setTargetCount(label, count); content.append(label);
      return [avatar, content];
    }
    function renderTargets() {
      const mode = chosenMode();
      nodes['mode-copy'].setAttribute('aria-pressed', String(mode === 'copy')); nodes['mode-forward'].setAttribute('aria-pressed', String(mode === 'forward'));
      const count = state.targets.selected.length;
      nodes['targets-summary'].textContent = modePending() ? 'Новый режим будет сохранён по кнопке «Далее».' : mode === null ? 'В сохранённом плане разные режимы.' : '';
      nodes['targets-summary'].hidden = !nodes['targets-summary'].textContent;
      nodes['selected-targets'].replaceChildren(); nodes['selected-targets'].hidden = count === 0;
      for (const target of state.targets.selected) {
        const choice = state.targets.choices.find(item => item.id === target.id); const title = choice ? choice.title : 'Недоступный канал';
        const row = document.createElement('div'); row.className = 'selected-target'; row.append(...targetPresentation(title, choice ? choice.subscriber_count : null)); nodes['selected-targets'].append(row);
      }
      nodes['targets-description'].textContent = state.capabilities.multipost_reason; nodes['targets-description'].hidden = !state.capabilities.multipost_reason;
      if (picker) renderPicker();
    }
    function openPicker() {
      if (!state || busy || flushingDurations || pending() || state.post.status !== 'draft') return;
      if (!state.capabilities.multipost) { if (state.capabilities.multipost_requires_subscription) showHelp('premium'); return; }
      picker = { revision: state.revision, mode: chosenMode(), entries: new Map(state.targets.selected.map(target => [target.id, { ...target }])) };
      renderPicker(); showStep('targets'); disabled(false); loadPickerMetadata();
    }
    function closePicker() { metadataGeneration++; metadataQueue = []; picker = null; stagedMode = null; targetControls.clear(); renderTargets(); showStep('initial'); disabled(busy); }
    function updatePickerCount() {
      if (!picker) return;
      nodes['picker-count'].textContent = 'Выбрано: ' + picker.entries.size + ' / 40';
      nodes['picker-mode'].textContent = picker.mode === null ? 'Режим не выбран. Вернитесь и выберите «Скопировать» или «Переслать».' : picker.mode === 'copy' ? 'Скопировать' : 'Переслать';
      const eligible = [...targetControls.values()].filter(control => !control.unavailable);
      nodes['select-all-targets'].checked = eligible.length > 0 && eligible.every(control => control.checkbox.checked);
    }
    function renderPicker() {
      if (!picker) return;
      nodes['target-choices'].replaceChildren(); targetControls.clear();
      const choices = state.targets.choices.slice(); const offered = new Set(choices.map(choice => choice.id));
      for (const target of picker.entries.values()) if (!offered.has(target.id)) choices.push({ id: target.id, title: 'Недоступный канал', modes: [], categories: [], disabled: true, reason: 'Канал больше недоступен. Уберите его из выбора, чтобы сохранить план.' });
      for (const choice of choices) {
        const active = picker.entries.get(choice.id); const unavailable = choice.disabled || picker.mode === null || !choice.modes.includes(picker.mode);
        const item = document.createElement('div'); item.className = 'target';
        const title = document.createElement('label'); title.className = 'target-title';
        const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.checked = Boolean(active); checkbox.setAttribute('aria-label', choice.title);
        const presentation = targetPresentation(choice.title, choice.subscriber_count); title.append(checkbox, ...presentation);
        title.addEventListener('click', event => { if (choice.disabled && !checkbox.checked) { event.preventDefault(); if (offered.has(choice.id)) showHelp('premium'); else feedback('Канал больше недоступен. Уберите его из выбора.', true); } });
        const controls = document.createElement('div'); controls.className = 'target-settings'; let category = null;
        if (choice.categories.length) {
          category = document.createElement('select'); category.setAttribute('aria-label', choice.title + ': компания');
          const prompt = document.createElement('option'); prompt.value = ''; prompt.textContent = 'Выберите компанию'; category.append(prompt);
          const singleSafe = choice.categories.length === 1 && !choice.categories[0].disabled && (!active || active.category_id === null);
          if (active && active.category_id !== null && !choice.categories.some(entry => entry.id === active.category_id)) { const missing = document.createElement('option'); missing.value = String(active.category_id); missing.textContent = 'Недоступная компания'; missing.disabled = true; missing.selected = true; category.append(missing); }
          for (const entry of choice.categories) { const option = document.createElement('option'); option.value = String(entry.id); option.textContent = entry.title + (entry.disabled && entry.reason ? ' — ' + entry.reason : ''); option.disabled = entry.disabled; option.selected = Boolean(active && active.category_id === entry.id) || singleSafe; category.append(option); }
          category.hidden = choice.categories.length === 1 && choice.categories[0].id === 0 && !choice.categories[0].disabled && (!active || active.category_id === null || active.category_id === 0);
          controls.append(category);
        }
        const control = { choice, checkbox, category, unavailable, countLabel: presentation[1].children[1] }; targetControls.set(choice.id, control);
        function stageCategory() { if (!picker || !checkbox.checked) return; const current = picker.entries.get(choice.id); if (current) current.category_id = category ? category.value === '' ? null : Number(category.value) : 0; }
        checkbox.addEventListener('change', () => {
          if (!picker || busy || pending() || checkbox.disabled) return;
          if (checkbox.checked) { if (unavailable) { checkbox.checked = false; feedback('Канал недоступен для выбранного режима.', true); return; } picker.entries.set(choice.id, { id: choice.id, mode: picker.mode, category_id: null }); stageCategory(); }
          else picker.entries.delete(choice.id);
          updatePickerCount(); disabled(busy);
        });
        if (category) category.addEventListener('change', () => { if (!category.disabled) stageCategory(); });
        item.append(title, controls);
        if (choice.reason || unavailable) { const reason = document.createElement('p'); reason.className = 'hint'; reason.textContent = choice.reason || 'Канал недоступен для выбранного режима. Уберите его из выбора или измените режим.'; item.append(reason); }
        nodes['target-choices'].append(item);
      }
      if (!choices.length) { const empty = document.createElement('p'); empty.className = 'hint'; empty.textContent = 'Нет дополнительных каналов для этого поста.'; nodes['target-choices'].append(empty); }
      updatePickerCount();
    }
    function selectedTargets() {
      if (!picker || picker.mode === null) throw new UiFailure('Выберите режим мультипостинга.');
      if (picker.entries.size > 40) throw new UiFailure('Можно выбрать не больше 40 каналов.');
      const targets = [];
      for (const [id] of picker.entries) {
        const control = targetControls.get(id);
        if (!control || control.unavailable || !control.choice.modes.includes(picker.mode)) throw new UiFailure('Уберите недоступные каналы или выберите доступное действие.');
        let categoryId = control.category ? null : 0;
        if (control.category) { if (control.category.value === '') throw new UiFailure('Выберите компанию для каждого выбранного канала.'); categoryId = Number(control.category.value); const category = control.choice.categories.find(item => item.id === categoryId); if (!category || category.disabled) throw new UiFailure('Выберите доступную компанию.'); }
        targets.push({ id, mode: picker.mode, category_id: categoryId });
      }
      return targets;
    }
    async function applyTargets() {
      if (!picker || busy || pending()) return;
      if (picker.revision !== state.revision) { feedback('Карточка изменилась. Выберите каналы заново.', true); picker = null; stagedMode = null; showStep('initial'); await refresh(); return; }
      let targets; try { targets = selectedTargets(); } catch (error) { feedback(userMessage(error), true); return; }
      const ok = await mutate('set_targets', { targets });
      picker = null; stagedMode = null; targetControls.clear(); renderTargets(); showStep('initial'); disabled(false);
      if (!ok) nodes['reload-state'].hidden = false;
    }
    async function applyStagedMode() {
      if (!modePending()) return true;
      let targets;
      try {
        targets = state.targets.selected.map(target => {
          const choice = state.targets.choices.find(item => item.id === target.id);
          if (!choice || choice.disabled || !choice.modes.includes(stagedMode)) throw new UiFailure('Уберите недоступные каналы или выберите доступное действие.');
          if (!integer(target.category_id, 0) || (choice.categories.length && !choice.categories.some(category => category.id === target.category_id && !category.disabled))) throw new UiFailure('Выберите доступную компанию для каждого канала.');
          return { id: target.id, mode: stagedMode, category_id: target.category_id };
        });
        validatePayload('set_targets', { targets });
      } catch (error) { feedback(userMessage(error), true); openPicker(); return false; }
      const ok = await mutate('set_targets', { targets }); stagedMode = null; renderTargets(); disabled(false); return ok;
    }
    function operationView() {
      const operation = state && state.operation;
      nodes['operation-panel'].hidden = !operation && !uncertainOperation;
      if (!operation && !uncertainOperation) return;
      const status = operation ? operation.status : 'processing';
      nodes['operation-heading'].textContent = status === 'sent' ? 'Пост опубликован' : status === 'scheduled' ? 'Пост отложен' : status === 'failed' ? 'Отправка не выполнена' : status === 'not_started' ? 'Отправка не начиналась' : 'Проверяем публикацию';
      nodes['operation-message'].textContent = operation && operation.message ? operation.message : 'Результат отправки пока не подтверждён. Не отправляйте пост повторно.';
      nodes['check-operation'].hidden = ['sent', 'scheduled', 'failed', 'not_started'].includes(status);
    }
    function renderTop() {
      const top = state.top; nodes['top-section'].hidden = !top.available;
      if (!top.available) return;
      nodes['top-toggle'].textContent = top.enabled ? 'Да · ' + exactDurationLabel(top.duration_minutes) : 'Нет';
      nodes['top-toggle'].setAttribute('aria-pressed', top.enabled ? 'true' : 'false');
      nodes['top-description'].textContent = 'После публикации никто не сможет отправить следующий пост в канал в течение ' + exactDurationLabel(top.duration_minutes) + '.';
      nodes['top-quota'].textContent = 'Остаток топов автора: ' + (top.unlimited ? 'без ограничений' : String(top.quota_remaining)) + '. Один опубликованный пост с топом — одно использование.';
      nodes['top-reason'].textContent = top.reason; nodes['top-reason'].hidden = !top.reason;
      nodes['top-block'].hidden = top.hold === null; nodes['top-block-link'].hidden = true; nodes['top-block-link'].setAttribute('href', '');
      if (top.hold !== null) {
        nodes['top-block-message'].textContent = top.hold.uncertain ? 'Результат предыдущей публикации с топом ещё не подтверждён. Отправка недоступна до проверки.' : 'В канале опубликован пост с удержанием в топе. Следующая публикация возможна после ' + top.hold.until_label + '.';
        if (top.hold.post_url) { nodes['top-block-link'].setAttribute('href', top.hold.post_url); nodes['top-block-link'].hidden = false; }
      }
    }
    function render(value) {
      state = normalizeState(value);
      if (state.operation && !PENDING_STATUSES.has(state.operation.status)) uncertainOperation = null;
      if (state.operation && state.operation.request_id && PENDING_STATUSES.has(state.operation.status)) uncertainOperation = state.operation.request_id;
      nodes['entry-message'].hidden = true; nodes.editor.hidden = false;
      durations.timer.render(state.timer); durations.repeat.render(state.repeat);
      renderTop();
      nodes['schedule-timezone'].textContent = state.schedule.timezone ? 'Часовой пояс: ' + state.schedule.timezone : '';
      nodes['repeat-reason'].textContent = state.capabilities.repeat_reason; nodes['repeat-reason'].hidden = !state.capabilities.repeat_reason;
      const reason = (!state.capabilities.can_publish && state.capabilities.publish_reason) || (!state.capabilities.can_schedule && state.capabilities.schedule_reason) || '';
      nodes['action-reason'].textContent = reason; nodes['action-reason'].hidden = !reason; renderTargets(); operationView(); disabled(busy);
      if (state.operation && state.operation.status === 'sent' && !sentCloseAttempted && sdk && typeof sdk.close === 'function') { sentCloseAttempted = true; try { sdk.close(); } catch (_) { feedback('Пост опубликован. Окно можно закрыть.', false); } }
    }
    function newId() { return randomRequestId(root.crypto); }
    async function send(action, payload, id) { return transport.send(action, state ? state.revision : null, payload, id || newId()); }
    async function refresh() {
      disabled(true); try { const reply = await send('read', {}); if (!reply.state) throw new UiFailure('Не удалось прочитать карточку. Откройте пост заново из бота.'); render(reply.state); } catch (error) { if (!state) unavailable(userMessage(error)); else feedback(userMessage(error), true); } finally { disabled(false); }
    }
    async function mutate(action, payload) {
      if (busy || pending()) return false; disabled(true); feedback('', false);
      try { const reply = await send(action, payload); if (!reply.state) throw new UiFailure('Настройки сохранены, но карточку нужно обновить.'); render(reply.state); feedback('', false); return true; }
      catch (error) { feedback(userMessage(error), true); await refresh(); if (error instanceof RequestFailure && error.code === 'subscription_required') showHelp('premium'); return false; } finally { disabled(false); }
    }
    function closeConfirmation() { if (confirmationTimer) clearTimeout(confirmationTimer); confirmationTimer = null; confirmation = null; if (nodes['confirmation-dialog'].open) nodes['confirmation-dialog'].close(); }
    async function prepare(action) {
      if (busy || pending()) return;
      if (modePending() || picker) { feedback('Сначала подтвердите выбор каналов.', true); if (!picker) openPicker(); return; }
      if (action === 'prepare_schedule') { let value; try { value = scheduleValue(calendar && calendar.day, nodes['schedule-hour'].value, nodes['schedule-minute'].value, calendar && calendar.today); } catch (error) { feedback(userMessage(error), true); return; } calendarSequence += 1; if (value !== state.schedule.local_value && !await mutate('set_schedule', { local_value: value })) return; }
      disabled(true); feedback('', false);
      try {
        const reply = await send(action, {}); if (reply.state) render(reply.state);
        const quote = reply.confirmation; if (!plain(quote) || typeof quote.token !== 'string' || !integer(quote.expires_at, 1) || quote.expires_at * 1000 <= Date.now() || !text(quote.summary, 4000)) throw new UiFailure('Не удалось подготовить подтверждение. Обновите карточку.');
        confirmation = { token: quote.token, expires_at: quote.expires_at, kind: action === 'prepare_schedule' ? 'schedule' : 'publish' }; nodes['confirmation-summary'].textContent = text(quote.summary, 4000); nodes['confirmation-summary'].hidden = confirmation.kind !== 'schedule'; nodes['confirm-operation'].textContent = confirmation.kind === 'schedule' ? 'Да, запланировать' : 'Да, опубликовать'; nodes['confirm-operation'].disabled = false; nodes['confirmation-expired'].hidden = true;
        nodes['confirmation-question'].textContent = confirmation.kind === 'schedule' ? 'Отложить пост в канале ' : 'Опубликовать пост в канале '; nodes['confirmation-channel'].textContent = state.post.channel_title;
        const previewTime = confirmation.kind === 'schedule' && state.schedule.local_value ? state.schedule.local_value.slice(11, 16) : state.post.time_label;
        nodes['preview-text'].textContent = state.post.preview_text; nodes['preview-author'].textContent = state.post.author_label; nodes['preview-author'].hidden = !state.post.author_label; nodes['preview-time'].textContent = previewTime; nodes['preview-time'].hidden = !previewTime; nodes['preview-truncated'].hidden = !state.post.preview_truncated; nodes['preview-media'].hidden = !state.post.has_media;
        nodes['confirmation-dialog'].showModal(); focusHeading('confirmation-heading'); confirmationTimer = setTimeout(() => { nodes['confirm-operation'].disabled = true; nodes['confirmation-expired'].hidden = false; updateMainButton(); }, Math.min(2147483647, Math.max(0, quote.expires_at * 1000 - Date.now())));
      } catch (error) { feedback(userMessage(error), true); if (error instanceof RequestFailure && error.reload) await refresh(); } finally { disabled(false); }
    }
    function schedulePoll() { if (pollTimer) clearTimeout(pollTimer); if (!uncertainOperation || pollsRemaining <= 0) return; pollTimer = setTimeout(() => { pollsRemaining -= 1; checkOperation(true); }, 2500); }
    async function checkOperation(automatic) {
      if (busy) { if (automatic) schedulePoll(); return; } if (!uncertainOperation) { await refresh(); return; }
      disabled(true);
      try { const reply = await send('operation_state', { request_id: uncertainOperation }); if (!reply.state) throw new UiFailure('Пока не удалось проверить результат.'); render(reply.state); if (!pending()) feedback('', false); }
      catch (error) { feedback(userMessage(error), true); } finally { disabled(false); operationView(); if (automatic && pending()) schedulePoll(); }
    }
    async function confirm() {
      if (busy || !confirmation || confirmation.expires_at * 1000 <= Date.now()) return;
      const quote = confirmation; closeConfirmation(); uncertainOperation = newId(); pollsRemaining = 4; disabled(true); feedback('Отправляем подтверждённое действие…', false); operationView();
      try { const reply = await send('confirm', { token: quote.token }, uncertainOperation); if (!reply.state) throw new UiFailure('Проверяем результат отправки. Повторной отправки не будет.'); render(reply.state); if (!pending()) feedback('', false); }
      catch (error) { feedback(userMessage(error), true); } finally { disabled(false); operationView(); if (pending()) schedulePoll(); }
    }
    async function mainAction() {
      if (nodes['help-dialog'].open) { closeHelp(); return; }
      if (nodes['confirmation-dialog'].open) return confirm();
      if (state && state.operation && state.operation.status === 'sent') { feedback('Пост опубликован. Окно можно закрыть.', false); return; }
      if (!state || busy || flushingDurations || pending() || nodes['next-step'].disabled) return;
      if (step === 'targets') return applyTargets();
      if (step === 'date') return prepare('prepare_schedule');
      if (!await flushCustomDurations()) return;
      if (modePending() && !await applyStagedMode()) return;
      if (state.post.forward_allowed === false && state.targets.selected.some(target => target.mode === 'forward')) { feedback('Пересылка для этого поста недоступна. Выберите «Скопировать».', true); openPicker(); return; }
      if (nodes['next-step'].disabled) return;
      if (!nodes['publication-time-dialog'].open && !nodes['confirmation-dialog'].open && !nodes['help-dialog'].open) { nodes['publication-time-dialog'].showModal(); focusHeading('publication-time-heading'); updateMainButton(); }
    }
    function closeTimeDialog() { if (nodes['publication-time-dialog'].open) nodes['publication-time-dialog'].close(); updateMainButton(); }
    function stageMode(mode) {
      if (!state || busy || pending() || state.post.status !== 'draft' || !state.capabilities.multipost || nodes['mode-' + mode].disabled) return;
      stagedMode = mode; renderTargets(); disabled(false);
    }
    nodes['mode-copy'].addEventListener('click', () => stageMode('copy')); nodes['mode-forward'].addEventListener('click', () => stageMode('forward'));
    nodes['add-targets'].addEventListener('click', openPicker); nodes['targets-back'].addEventListener('click', () => { if (!busy && !pending()) closePicker(); });
    nodes['select-all-targets'].addEventListener('change', () => {
      if (!picker || busy || pending() || nodes['select-all-targets'].disabled) return;
      if (!nodes['select-all-targets'].checked) picker.entries.clear();
      else for (const [id, control] of targetControls) if (!control.unavailable && !picker.entries.has(id)) picker.entries.set(id, { id, mode: picker.mode, category_id: control.category && control.category.value !== '' ? Number(control.category.value) : control.category ? null : 0 });
      renderPicker(); disabled(false);
    });
    nodes['next-step'].addEventListener('click', mainAction);
    nodes['calendar-previous'].addEventListener('click', () => navigateCalendar(-1)); nodes['calendar-next'].addEventListener('click', () => navigateCalendar(1)); nodes['calendar-retry'].addEventListener('click', loadCalendar);
    for (const [part, maximum] of [['hour', 23], ['minute', 59]]) {
      const input = nodes['schedule-' + part];
      input.addEventListener('keydown', event => { if (event.key !== 'Enter') return; event.preventDefault(); if (part === 'hour' && typeof nodes['schedule-minute'].focus === 'function') nodes['schedule-minute'].focus(); else if (typeof input.blur === 'function') input.blur(); });
      for (const [direction, offset] of [['up', 1], ['down', -1]]) {
        const button = nodes['schedule-' + part + '-' + direction]; button.replaceChildren(chevronIcon(direction === 'up'));
        button.addEventListener('click', () => { if (button.disabled || busy || pending() || !state || state.post.status !== 'draft' || !state.capabilities.can_schedule) return; const current = /^[0-9]{0,2}$/.test(input.value) ? Number(input.value) : 0; input.value = String(Math.max(0, Math.min(maximum, current + offset))).padStart(2, '0'); });
      }
    }
    nodes['date-back'].addEventListener('click', () => { if (!busy && !pending()) showStep('initial'); });
    nodes['publish-post'].addEventListener('click', () => { if (nodes['publish-post'].disabled || !nodes['publication-time-dialog'].open) return; closeTimeDialog(); return prepare('prepare_publish'); });
    nodes['postpone-post'].addEventListener('click', () => { if (nodes['postpone-post'].disabled || !nodes['publication-time-dialog'].open) return; closeTimeDialog(); return openCalendar(); });
    nodes['cancel-publication-time'].addEventListener('click', closeTimeDialog); nodes['publication-time-dialog'].addEventListener('cancel', closeTimeDialog);
    function showHelp(kind) {
      const messages = {
        timer: ['timer', 'Таймер удаления', 'Вы можете установить через какое время после выхода поста, он будет удалён.\n\nЗадайте время, через которое нужно удалить пост, выбрав один из предложенных вариантов либо введя время вручную.'],
        repeat: ['repeat', 'Автоповтор', 'Вы можете настроить автоматическое повторение выхода поста через заданный промежуток времени.'],
        targets: ['targets', 'Мультипостинг', 'Выберите каналы, в которые хотите скопировать или переслать пост. Публикация выйдет сразу во всех выбранных каналах.'],
        premium: ['premium', 'У канала нет премиум-подписки', 'Чтобы продолжить, приобретите подписку для канала'],
        manual: ['manual', 'Требуется ручное удаление', 'Пост понадобится удалить вручную. Автоудаление для этого срока недоступно.']
      };
      const value = messages[kind]; if (!value) return;
      nodes['help-dialog'].dataset.kind = kind;
      nodes['help-illustration'].replaceChildren(illustration(value[0])); nodes['help-heading'].textContent = value[1]; nodes['help-message'].textContent = value[2]; nodes['help-safety'].textContent = '48 ч в меню — фактически 47 ч 55 мин. Больший срок требует ручного удаления.'; nodes['help-safety'].hidden = kind !== 'timer' || !state || state.timer.selected < 2875;
      if (!nodes['help-dialog'].open) nodes['help-dialog'].showModal(); focusHeading('help-heading'); updateMainButton();
    }
    for (const kind of ['timer', 'repeat', 'targets']) nodes[kind + '-help'].addEventListener('click', () => showHelp(kind));
    function closeHelp() { if (nodes['help-dialog'].open) nodes['help-dialog'].close(); updateMainButton(); }
    nodes['close-help'].addEventListener('click', closeHelp); nodes['help-close-x'].addEventListener('click', closeHelp); nodes['help-dialog'].addEventListener('cancel', closeHelp);
    nodes['reload-state'].addEventListener('click', refresh); nodes['check-operation'].addEventListener('click', () => checkOperation(false));
    nodes['top-toggle'].addEventListener('click', () => { if (!state || nodes['top-toggle'].disabled || busy || pending()) return; return mutate('set_top', { enabled: !state.top.enabled }); });
    nodes['top-refresh'].addEventListener('click', () => { if (!busy && !pending()) return refresh(); });
    nodes['cancel-confirmation'].addEventListener('click', () => { closeConfirmation(); updateMainButton(); }); nodes['confirm-operation'].addEventListener('click', confirm); nodes['confirmation-dialog'].addEventListener('cancel', () => { closeConfirmation(); updateMainButton(); });
    function backAction() { if (busy || pending()) return; if (nodes['help-dialog'].open) closeHelp(); else if (nodes['confirmation-dialog'].open) { closeConfirmation(); updateMainButton(); } else if (nodes['publication-time-dialog'].open) closeTimeDialog(); else if (step === 'targets') closePicker(); else showStep('initial'); }
    nodes['confirmation-illustration'].replaceChildren(illustration('duck'));
    try {
      if (!sdk || typeof sdk.initData !== 'string' || !sdk.initData.length) throw new UiFailure('Вернитесь в бота, откройте пост и нажмите «Далее».');
      if (typeof root.fetch !== 'function' || typeof root.AbortController !== 'function' || typeof nodes['confirmation-dialog'].showModal !== 'function') throw new UiFailure('Обновите Telegram, чтобы открыть настройки отправки.');
      const launch = extractLaunch(root.location.href);
      const signedData = sdk.initData;
      transport = createTransport({ fetch: root.fetch.bind(root), endpoint: root.location.href, initData: signedData, launch, AbortController: root.AbortController });
      cleanAuthFragment(root.location, root.history, launch);
      if (nativeMain) { try { nativeMain.onClick(mainAction); } catch (_) { nativeMainUsable = false; } }
      if (nativeBack) { try { nativeBack.onClick(backAction); } catch (_) { nativeBackUsable = false; } }
      showStep('initial');
      applyTheme(); if (typeof sdk.onEvent === 'function') sdk.onEvent('themeChanged', applyTheme); if (typeof sdk.ready === 'function') sdk.ready(); if (typeof sdk.expand === 'function') sdk.expand(); refresh();
    } catch (error) { unavailable(userMessage(error)); }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true }); else start();
})(typeof globalThis === 'undefined' ? window : globalThis);
