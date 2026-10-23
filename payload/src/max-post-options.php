<?php

declare(strict_types=1);

/** Additional MAX post settings; the host supplies the existing permission locks. */
trait GitPostedMaxPostOptions
{
    private function maxDecodeOptions(array $source): array
    {
        $raw = $source['options_json'] ?? null;
        if ($raw === null || $raw === '') return [];
        if (!is_string($raw)) throw new RuntimeException('Настройки публикации МАКС повреждены.');
        try { $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException $exception) { throw new RuntimeException('Настройки публикации МАКС повреждены.', 0, $exception); }
        if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
            throw new RuntimeException('Настройки публикации МАКС повреждены.');
        }
        return $decoded;
    }

    /** Unknown fields (including first_comment) deliberately survive every edit. */
    private function maxPostOptions(array $post): array
    {
        $options = array_replace([
            'reply_mid' => null,
            'link_preview' => true,
            'autosignature_enabled' => false,
            'autosignature_text' => '',
            'autosignature_html' => null,
            'replacement_enabled' => false,
            'replacement_url' => '',
        ], $this->maxDecodeOptions($post));
        foreach (['link_preview', 'autosignature_enabled', 'replacement_enabled'] as $key) {
            if (!is_bool($options[$key])) throw new RuntimeException('Настройки публикации МАКС повреждены.');
        }
        foreach (['autosignature_text', 'replacement_url'] as $key) {
            if (!is_string($options[$key])) throw new RuntimeException('Настройки публикации МАКС повреждены.');
        }
        foreach (['reply_mid', 'autosignature_html'] as $key) {
            if ($options[$key] !== null && !is_string($options[$key])) {
                throw new RuntimeException('Настройки публикации МАКС повреждены.');
            }
        }
        return $options;
    }

    /** Returns an OPTIONS array, to JSON-encode once on creation of a new post. */
    private function maxApplyChannelOptions(array $post, array $channel): array
    {
        $defaults = $this->maxDecodeOptions($channel);
        unset($defaults['reply_mid']); // a reply always targets one explicit publication
        return $this->maxPostOptions(['options_json' => $this->encodeJson(
            array_replace($defaults, $this->maxDecodeOptions($post))
        )]);
    }

    private function maxOptionsHttpUrl(string $url): bool
    {
        return $url !== '' && strlen($url) <= 2048
            && preg_match('/[\x00-\x20\x7f]/', $url) !== 1
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && (string) parse_url($url, PHP_URL_HOST) !== '';
    }

    private function maxOptionsReplaceTextUrls(string $text, string $replacement): string
    {
        return preg_replace_callback('~https?://[^\s<>"\x27]+~iu', static function (array $match) use ($replacement): string {
            $url = $match[0];
            $suffix = '';
            // Keep ordinary prose punctuation, including balanced URL parentheses.
            while ($url !== '') {
                $last = substr($url, -1);
                if (str_contains('.,!?:;', $last)
                    || ($last === ')' && substr_count($url, ')') > substr_count($url, '('))
                    || ($last === ']' && substr_count($url, ']') > substr_count($url, '['))
                    || ($last === '}' && substr_count($url, '}') > substr_count($url, '{'))) {
                    $suffix = $last . $suffix;
                    $url = substr($url, 0, -1);
                } else break;
            }
            return $replacement . $suffix;
        }, $text) ?? throw new RuntimeException('Не удалось заменить ссылки в тексте МАКС.');
    }

    private function maxOptionsReplaceHtmlUrls(string $html, string $replacement): string
    {
        // Tokenize tags with quoted attributes intact, including > inside href.
        $parts = preg_split('~(<(?:[^>"\x27]|"[^"]*"|\x27[^\x27]*\x27)*>)~u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) throw new RuntimeException('Не удалось обработать оформление МАКС.');
        foreach ($parts as $index => $part) {
            if ($part !== '' && $part[0] === '<') {
                if (preg_match('~\A<a(?=\s|>)~i', $part) === 1) {
                    $parts[$index] = preg_replace_callback('~(\bhref\s*=\s*)(["\x27])(.*?)\2~isu', function (array $match) use ($replacement): string {
                        $href = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        return $this->maxOptionsHttpUrl($href)
                            ? $match[1] . $match[2] . htmlspecialchars($replacement, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $match[2]
                            : $match[0];
                    }, $part) ?? throw new RuntimeException('Не удалось заменить ссылку МАКС.');
                }
            } else {
                $plain = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $parts[$index] = htmlspecialchars($this->maxOptionsReplaceTextUrls($plain, $replacement), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
        return implode('', $parts);
    }

    /** @return array{text:string,html:string,buttons:array} */
    private function maxEffectivePostContent(array $post): array
    {
        $text = (string) ($post['text'] ?? '');
        $html = (string) ($post['text_html'] ?? '');
        $buttons = $this->decodeJson((string) ($post['buttons_json'] ?? ''));
        $options = $this->maxPostOptions($post);
        // A native forward is owned by its source. Never rewrite its content or buttons.
        if (trim((string) ($post['forward_message_id'] ?? '')) !== '') {
            return compact('text', 'html', 'buttons');
        }
        if ($options['autosignature_enabled'] && $options['autosignature_text'] !== '') {
            $signature = $options['autosignature_text'];
            $signatureHtml = (string) ($options['autosignature_html'] ?? '');
            if ($html !== '' || $signatureHtml !== '') {
                $html = ($html !== '' ? $html : htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
                    . ($text !== '' ? "\n\n" : '')
                    . ($signatureHtml !== '' ? $signatureHtml : htmlspecialchars($signature, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            }
            $text .= ($text !== '' ? "\n\n" : '') . $signature;
        }
        if ($options['replacement_enabled'] && $options['replacement_url'] !== '') {
            $url = $options['replacement_url'];
            if (!$this->maxOptionsHttpUrl($url)) throw new RuntimeException('Укажите корректную ссылку HTTP или HTTPS.');
            $text = $this->maxOptionsReplaceTextUrls($text, $url);
            if ($html !== '') $html = $this->maxOptionsReplaceHtmlUrls($html, $url);
            foreach ($buttons as &$row) {
                if (!is_array($row)) continue;
                foreach ($row as &$button) {
                    if (is_array($button) && ($button['type'] ?? 'link') === 'link'
                        && is_string($button['url'] ?? null) && $this->maxOptionsHttpUrl($button['url'])) {
                        $button['url'] = $url;
                    }
                }
                unset($button);
            }
            unset($row);
        }
        if (mb_strlen($text) > 4000 || ($html !== '' && mb_strlen($html) > 4000)) {
            throw new RuntimeException('Текст вместе с подписью и оформлением длиннее 4000 символов. Сократите текст или подпись; содержимое сохранено.');
        }
        return compact('text', 'html', 'buttons');
    }

    /** Must be called immediately before reserving a new publication attempt. */
    private function maxPostReplyLink(array $post, string $chatId): ?array
    {
        $mid = trim((string) ($this->maxPostOptions($post)['reply_mid'] ?? ''));
        if ($mid === '') return null;
        if (trim((string) ($post['forward_message_id'] ?? '')) !== '') {
            throw new RuntimeException('Ответ и репост нельзя совмещать. Уберите ответ или создайте обычный пост.');
        }
        if (preg_match('/\A[A-Za-z0-9_.:-]{1,255}\z/D', $mid) !== 1 || $chatId === '') {
            throw new RuntimeException('Некорректный ID поста для ответа.');
        }
        try { $message = $this->firstMessage($this->api->getMessage($mid)); }
        catch (Throwable $exception) {
            throw new RuntimeException('Не удалось проверить пост для ответа. Повторите позже; настройки сохранены.', 0, $exception);
        }
        $foundMid = is_array($message) ? trim((string) ($message['body']['mid'] ?? $message['mid'] ?? $message['message_id'] ?? '')) : '';
        $foundChat = is_array($message) ? trim((string) ($message['recipient']['chat_id'] ?? '')) : '';
        if ($foundMid !== $mid || $foundChat !== $chatId) {
            throw new RuntimeException('Пост для ответа не найден в выбранном канале. Перешлите пост именно из этого канала.');
        }
        return ['type' => 'reply', 'mid' => $mid];
    }

    /** Direct rows for draft/scheduled/failed post screens. */
    private function maxOptionsRows(array $post): array
    {
        if (!in_array((string) ($post['status'] ?? ''), ['draft', 'scheduled', 'failed'], true)) return [];
        $options = $this->maxPostOptions($post);
        $prefix = 'gp:opt:p:' . (int) $post['id'] . ':';
        $forward = trim((string) ($post['forward_message_id'] ?? '')) !== '';
        return [
            [GitPostedMaxApi::callbackButton($forward ? 'Ответ: недоступен для репоста' : (!empty($options['reply_mid']) ? 'Ответ: настроен' : 'Ответ на пост'), $prefix . 'reply')],
            [GitPostedMaxApi::callbackButton($options['link_preview'] ? '✅ Превью ссылок' : '☑️ Превью ссылок', $prefix . 'preview:' . ($options['link_preview'] ? '0' : '1'))],
            [GitPostedMaxApi::callbackButton($forward ? 'Подпись: не применяется к репосту' : ($options['autosignature_enabled'] ? '✅ Автоподпись' : '☑️ Автоподпись'), $prefix . 'signature')],
            [GitPostedMaxApi::callbackButton($forward ? 'Замена ссылок: не применяется к репосту' : ($options['replacement_enabled'] ? '✅ Замена ссылок' : '☑️ Замена ссылок'), $prefix . 'replacement')],
        ];
    }

    /** Append these rows to the existing channel post-defaults screen. */
    private function maxChannelOptionsRows(array $channel): array
    {
        return [[GitPostedMaxApi::callbackButton('Подпись, ссылки и превью новых постов', 'gp:opt:c:' . (int) $channel['id'] . ':menu')]];
    }

    private function maxWithOptionsTarget(string $userId, string $scope, int $id, callable $operation): mixed
    {
        if ($scope === 'p') {
            return $this->withManagedMaxPostLock($userId, $id, 'manage', ['draft', 'scheduled', 'failed'],
                static fn (array $post, array $channel): mixed => $operation($post + ['chat_id' => $channel['chat_id']]));
        }
        if ($scope === 'c') return $this->withOwnedMaxChannelLock($userId, $id, $operation);
        throw new RuntimeException('Некорректный раздел настроек МАКС.');
    }

    private function maxWriteOptionsLocked(string $scope, int $id, array $options): void
    {
        $table = $scope === 'p' ? 'posted_max_posts' : 'posted_max_channels';
        $this->pdo->prepare('UPDATE ' . $table . ' SET options_json=:options WHERE id=:id')
            ->execute(['options' => $this->encodeJson($options), 'id' => $id]);
    }

    private function maxClearOptionsInputLocked(string $userId, string $scope, int $id): void
    {
        $user = $this->user($userId);
        $data = $this->decodeJson((string) ($user['state_data'] ?? ''));
        if (($user['state'] ?? '') === 'max_options_input' && ($data['scope'] ?? '') === $scope && (int) ($data['id'] ?? 0) === $id) {
            $this->setState($userId, null, []);
        }
    }

    private function maxOptionsScreen(array $source, string $scope, string $view = 'menu'): array
    {
        $options = $this->maxPostOptions($source);
        $prefix = 'gp:opt:' . $scope . ':' . (int) $source['id'] . ':';
        $forward = $scope === 'p' && trim((string) ($source['forward_message_id'] ?? '')) !== '';
        $heading = $scope === 'c' ? "НАСТРОЙКИ НОВЫХ ПОСТОВ\nПрименяются к новым черновикам; готовые посты сохраняют свои настройки." : 'НАСТРОЙКИ ПОСТА';
        if ($forward && $view !== 'menu') {
            return ['У репоста сохраняется содержимое источника. Подпись и замена ссылок к нему не применяются. Создайте обычный пост для этих настроек.',
                [[GitPostedMaxApi::callbackButton('← Назад', $prefix . 'menu')]]];
        }
        if ($view === 'signature') {
            $text = $heading . "\n\nАвтоподпись: " . ($options['autosignature_enabled'] ? 'включена' : 'выключена')
                . "\n\n" . ($options['autosignature_text'] !== '' ? $options['autosignature_text'] : 'Подпись пока не задана.');
            $rows = [
                [GitPostedMaxApi::callbackButton('Изменить подпись', $prefix . 'siginput')],
                [GitPostedMaxApi::callbackButton($options['autosignature_enabled'] ? 'Выключить' : 'Включить', $prefix . 'sigtoggle:' . ($options['autosignature_enabled'] ? '0' : '1'))],
                [GitPostedMaxApi::callbackButton('Убрать подпись', $prefix . 'sigclear')],
            ];
        } elseif ($view === 'replacement') {
            $text = $heading . "\n\nЗамена всех HTTP(S)-ссылок в тексте, ссылках в словах и URL-кнопках: "
                . ($options['replacement_enabled'] ? 'включена' : 'выключена') . "\n\n"
                . ($options['replacement_url'] !== '' ? $options['replacement_url'] : 'Новая ссылка пока не задана.');
            $rows = [
                [GitPostedMaxApi::callbackButton('Указать новую ссылку', $prefix . 'replinput')],
                [GitPostedMaxApi::callbackButton($options['replacement_enabled'] ? 'Выключить' : 'Включить', $prefix . 'repltoggle:' . ($options['replacement_enabled'] ? '0' : '1'))],
                [GitPostedMaxApi::callbackButton('Убрать замену', $prefix . 'replclear')],
            ];
        } else {
            $text = $heading;
            if ($forward) $text .= "\n\nУ репоста сохраняется содержимое источника. Ответ, автоподпись и замена ссылок к нему не применяются.";
            $rows = $scope === 'p' ? $this->maxOptionsRows($source) : [
                [GitPostedMaxApi::callbackButton($options['link_preview'] ? '✅ Превью ссылок' : '☑️ Превью ссылок', $prefix . 'preview:' . ($options['link_preview'] ? '0' : '1'))],
                [GitPostedMaxApi::callbackButton('Автоподпись', $prefix . 'signature')],
                [GitPostedMaxApi::callbackButton('Замена ссылок', $prefix . 'replacement')],
            ];
            if ($scope === 'p' && !empty($options['reply_mid'])) {
                $text .= "\n\nОтвет на пост: " . $options['reply_mid'];
                $rows[] = [GitPostedMaxApi::callbackButton('Убрать ответ', $prefix . 'replyclear')];
            }
        }
        $rows[] = [GitPostedMaxApi::callbackButton('← Назад', $view === 'menu'
            ? ($scope === 'p' ? ((string) $source['status'] === 'draft' ? 'gp:more:' : 'gp:view:') . (int) $source['id'] : 'gp:channelpostsettings:' . (int) $source['id'])
            : $prefix . 'menu')];
        return [$text, $rows];
    }

    private function maxShowOptions(string $callbackId, string $userId, string $scope, int $id, string $view = 'menu', ?string $chatId = null, bool $clearInput = true): void
    {
        $source = $this->maxWithOptionsTarget($userId, $scope, $id, function (array $source) use ($userId, $scope, $id, $clearInput): array {
            if ($clearInput) $this->maxClearOptionsInputLocked($userId, $scope, $id);
            return $source;
        });
        [$text, $rows] = $this->maxOptionsScreen($source, $scope, $view);
        if ($chatId !== null) $this->api->sendMessage($chatId, $text, [], $rows);
        else $this->api->answerCallback($callbackId, $text, $rows);
    }

    private function handleMaxOptionsCallback(string $callbackId, string $userId, string $data): bool
    {
        if (!str_starts_with($data, 'gp:opt:')) return false;
        if (preg_match('/\Agp:opt:([pc]):([1-9][0-9]{0,17}):([a-z]+)(?::([01]))?\z/D', $data, $match) !== 1) {
            throw new RuntimeException('Кнопка настроек МАКС устарела.');
        }
        [, $scope, $rawId, $action] = $match;
        $id = (int) $rawId;
        $value = $match[4] ?? '';
        if (!in_array($action, ['menu', 'signature', 'replacement', 'reply', 'siginput', 'replinput', 'preview', 'sigtoggle', 'repltoggle', 'sigclear', 'replclear', 'replyclear'], true)) {
            throw new RuntimeException('Кнопка настроек МАКС устарела.');
        }
        if (in_array($action, ['preview', 'sigtoggle', 'repltoggle'], true) && $value === '') {
            throw new RuntimeException('Кнопка настроек МАКС устарела.');
        }
        $view = in_array($action, ['signature', 'siginput', 'sigtoggle', 'sigclear'], true) ? 'signature'
            : (in_array($action, ['replacement', 'replinput', 'repltoggle', 'replclear'], true) ? 'replacement' : 'menu');
        if (in_array($action, ['menu', 'signature', 'replacement'], true)) {
            $this->maxShowOptions($callbackId, $userId, $scope, $id, $view);
            return true;
        }
        $this->maxWithOptionsTarget($userId, $scope, $id, function (array $source) use ($userId, $scope, $id, $action, $value): void {
            $options = $this->maxPostOptions($source);
            $forward = $scope === 'p' && trim((string) ($source['forward_message_id'] ?? '')) !== '';
            if ($scope === 'c' && in_array($action, ['reply', 'replyclear'], true)) {
                throw new RuntimeException('Ответ настраивается отдельно у конкретного поста.');
            }
            if ($forward && !in_array($action, ['preview', 'replyclear', 'sigclear', 'replclear'], true)) {
                throw new RuntimeException('У репоста нельзя изменить источник, добавить ответ, подпись или заменить ссылки. Создайте обычный пост для этих настроек.');
            }
            if (in_array($action, ['reply', 'siginput', 'replinput'], true)) {
                $this->setState($userId, 'max_options_input', ['scope' => $scope, 'id' => $id, 'field' => $action, 'token' => bin2hex(random_bytes(12))]);
                return;
            }
            match ($action) {
                'preview' => $options['link_preview'] = $value === '1',
                'sigtoggle' => $options['autosignature_enabled'] = $value === '1',
                'repltoggle' => $options['replacement_enabled'] = $value === '1',
                'replyclear' => $options['reply_mid'] = null,
                'sigclear' => [$options['autosignature_enabled'], $options['autosignature_text'], $options['autosignature_html']] = [false, '', null],
                'replclear' => [$options['replacement_enabled'], $options['replacement_url']] = [false, ''],
            };
            if ($options['autosignature_enabled'] && $options['autosignature_text'] === '') throw new RuntimeException('Сначала задайте текст подписи.');
            if ($options['replacement_enabled'] && !$this->maxOptionsHttpUrl($options['replacement_url'])) throw new RuntimeException('Сначала задайте ссылку HTTP или HTTPS.');
            if ($scope === 'p') $this->maxEffectivePostContent(array_replace($source, ['options_json' => $this->encodeJson($options)]));
            $this->maxWriteOptionsLocked($scope, $id, $options);
            $this->maxClearOptionsInputLocked($userId, $scope, $id);
        });
        if (in_array($action, ['reply', 'siginput', 'replinput'], true)) {
            $prompt = match ($action) {
                'reply' => 'Перешлите пост из выбранного канала или отправьте его ID (mid). Пост для ответа будет проверен заново перед публикацией.',
                'siginput' => 'Отправьте подпись отдельным сообщением. Оформление и ссылки сохранятся. Подпись добавляется в конец обычного поста.',
                'replinput' => 'Отправьте одну ссылку HTTP или HTTPS. Она заменит все HTTP(S)-ссылки в тексте, ссылках в словах и URL-кнопках обычного поста.',
            };
            $this->api->answerCallback($callbackId, $prompt . "\n\nОтмена — вернуться без изменения.", [[GitPostedMaxApi::callbackButton('← Назад', 'gp:opt:' . $scope . ':' . $id . ':menu')]]);
        } else $this->maxShowOptions($callbackId, $userId, $scope, $id, $view);
        return true;
    }

    /** Input hook belongs after /start and /menu handling, before normal drafts. */
    private function handleMaxOptionsInput(string $chatId, string $userId, string $text, array $message): bool
    {
        // A failed UI response must not turn the retried setting text into a post.
        $updateKey = $this->processingUpdateKey ?? null;
        if (is_string($updateKey) && $updateKey !== '') {
            $effect = $this->pdo->prepare('SELECT effect_type,result_json FROM posted_max_update_effects WHERE update_key=:key LIMIT 1');
            $effect->execute(['key' => $updateKey]);
            $saved = $effect->fetch();
            if (is_array($saved) && ($saved['effect_type'] ?? '') === 'post_options_input') {
                $result = $this->decodeJson((string) $saved['result_json']);
                $this->maxShowOptions('', $userId, (string) $result['scope'], (int) $result['id'], (string) ($result['view'] ?? 'menu'), $chatId, false);
                return true;
            }
            if (is_array($saved)) return false;
        }
        $account = $this->user($userId);
        if (($account['state'] ?? '') !== 'max_options_input') return false;
        $input = $this->decodeJson((string) ($account['state_data'] ?? ''));
        $scope = (string) ($input['scope'] ?? '');
        $id = (int) ($input['id'] ?? 0);
        $field = (string) ($input['field'] ?? '');
        $token = (string) ($input['token'] ?? '');
        if (!in_array($scope, ['p', 'c'], true) || $id <= 0 || !in_array($field, ['reply', 'siginput', 'replinput'], true) || $token === '') {
            throw new RuntimeException('Настройка МАКС устарела. Откройте её заново.');
        }
        $view = $field === 'siginput' ? 'signature' : ($field === 'replinput' ? 'replacement' : 'menu');
        try {
            $this->maxWithOptionsTarget($userId, $scope, $id, function (array $source) use ($userId, $scope, $id, $field, $token, $text, $message, $view): void {
                $current = $this->pdo->prepare('SELECT state,state_data FROM posted_max_users WHERE max_user_id=:user FOR UPDATE');
                $current->execute(['user' => $userId]);
                $row = $current->fetch();
                $active = is_array($row) ? $this->decodeJson((string) ($row['state_data'] ?? '')) : [];
                if (!is_array($row) || ($row['state'] ?? '') !== 'max_options_input' || ($active['scope'] ?? '') !== $scope
                    || (int) ($active['id'] ?? 0) !== $id || ($active['field'] ?? '') !== $field
                    || !hash_equals($token, (string) ($active['token'] ?? ''))) {
                    throw new RuntimeException('Настройка изменилась. Откройте нужный пункт ещё раз.');
                }
                $this->completeMaxUpdateEffectLocked('post_options_input', function () use ($source, $scope, $id, $field, $text, $message, $userId, $view): array {
                    if (mb_strtolower(trim($text)) !== 'отмена') {
                        if ($scope === 'p' && trim((string) ($source['forward_message_id'] ?? '')) !== '') {
                            throw new RuntimeException('Эта настройка недоступна для репоста. Создайте обычный пост.');
                        }
                        $options = $this->maxPostOptions($source);
                        if ($field === 'reply') {
                            if ($scope !== 'p') throw new RuntimeException('Ответ задаётся у конкретного поста.');
                            $link = is_array($message['link'] ?? null) ? $message['link'] : (array) ($message['body']['link'] ?? []);
                            $forward = ($link['type'] ?? '') === 'forward' ? $link : [];
                            $mid = trim((string) ($forward['message']['body']['mid'] ?? $forward['message']['mid'] ?? $forward['mid'] ?? $text));
                            $options['reply_mid'] = $mid;
                            $this->maxPostReplyLink(array_replace($source, ['options_json' => $this->encodeJson($options)]), (string) $source['chat_id']);
                        } elseif ($field === 'siginput') {
                            $content = $this->trimmedMessageContent($this->forwardedContentMessage($message) ?? $message);
                            $signature = (string) ($content['text'] ?? $text);
                            if (trim($signature) === '' || mb_strlen($signature) > 1000) throw new RuntimeException('Подпись должна содержать от 1 до 1000 символов.');
                            $options['autosignature_text'] = $signature;
                            $options['autosignature_html'] = $content['html'] ?? null;
                            $options['autosignature_enabled'] = true;
                        } else {
                            $url = trim($text);
                            if (!$this->maxOptionsHttpUrl($url)) throw new RuntimeException('Отправьте одну корректную ссылку HTTP или HTTPS.');
                            $options['replacement_url'] = $url;
                            $options['replacement_enabled'] = true;
                        }
                        if ($scope === 'p') $this->maxEffectivePostContent(array_replace($source, ['options_json' => $this->encodeJson($options)]));
                        $this->maxWriteOptionsLocked($scope, $id, $options);
                    }
                    $this->setState($userId, null, []);
                    return compact('scope', 'id', 'view');
                });
            });
        } catch (Throwable $exception) {
            $notice = $exception instanceof PDOException ? 'Не удалось сохранить настройки МАКС. Повторите позже.' : $exception->getMessage();
            $this->api->sendMessage($chatId, $notice . "\n\nПовторите ввод или отправьте «Отмена».");
            return true;
        }
        $this->maxShowOptions('', $userId, $scope, $id, $view, $chatId, false);
        return true;
    }
}
