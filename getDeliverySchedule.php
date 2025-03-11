//вывод дат и промежутков доставки по заданным параметрам
if (!function_exists("getDeliverySchedule")) {
    function getDeliverySchedule($cityId, $weight, $cost, $deliveryId = null)
    {
        $weeks_arr = ['вс','пн','вт','ср','чт','пт','cб'];
        global $DB; // Глобальный объект подключения Bitrix

        $cityId = intval($cityId);

        // Если city_id = 5 и передан $deliveryId, выбираем только одну запись по id доставки
        if ($cityId === 5 && !is_null($deliveryId)) {
            $deliveryId = intval($deliveryId);
            $sql = "SELECT * FROM vysota_zones_order WHERE id = {$deliveryId}";
        } else {
            $sql = "SELECT * FROM vysota_zones_order WHERE city_id = {$cityId}";
        }

        $res = $DB->Query($sql);
        $rows = [];
        while ($row = $res->Fetch()) {
            $rows[] = $row;
        }
        if (empty($rows)) {
            return []; // Нет настроек для данного города/доставки
        }

        // Приведение входных параметров к минимальным значениям
        // Если в записях задан min_cost и min_weight, они будут использованы ниже.
        $minCost = 0;
        $minWeight = 0;
        // Фактические параметры заказа (потом их сравним с минимальными)
        $effectiveWeight = max($weight, $minWeight);
        $effectiveCost = max($cost, $minCost);

        $schedule = [];
        $currentDateTime = new DateTime();
        $availableCount = 0;
        $dayOffset = 0;

        // Собираем 10 дней (даже если для дня будут ошибки — они попадут в ключ error)
        while ($availableCount < 6) {
            $day = clone $currentDateTime;
            $day->modify("+{$dayOffset} days");
            $dayOffset++;
            $dayOfWeek = (int)$day->format('N'); // 1 = понедельник, 7 = воскресенье
            $dayOfWeekFormated= $weeks_arr[$dayOfWeek];
            $explanation = "Дата: " . $day->format('Y-m-d') . " (день недели: {$dayOfWeek}). ";
            $errors = []; // здесь будут сообщения об ошибках для данного дня

            // Выбираем записи, где для данного дня (по номеру недели) возможна доставка
            $applicableRows = [];
            foreach ($rows as $row) {
                $allowedDays = array_map('intval', explode(',', $row['delivery_days']));
                if (in_array($dayOfWeek, $allowedDays)) {
                    // Если запланированный день находится в текущей неделе, проверяем окончание приёма заказов для этой недели
                    if ($day->format('W') == $currentDateTime->format('W')) {
                        // Находим дату понедельника текущей недели
                        $monday = new DateTime();
                        $monday->modify('Monday this week');
                        // Определяем дату окончания приёма заказов: delivery_end_day указывает номер дня недели, прибавляем (delivery_end_day - 1) дней
                        $targetDayOffset = intval($row['delivery_end_day']) - 1;
                        $acceptanceEndDate = clone $monday;
                        $acceptanceEndDate->modify("+{$targetDayOffset} days");
                        // Устанавливаем время окончания приёма заказов
                        $acceptanceTime = date_parse($row['delivery_end_time']);
                        $acceptanceEndDate->setTime($acceptanceTime['hour'], $acceptanceTime['minute'], $acceptanceTime['second']);

                        if ($currentDateTime > $acceptanceEndDate) {
                            $explanation .= "Запись с диапазоном {$row['delivery_time_range']} — прием заказов для этой недели завершен (окончание: " . $acceptanceEndDate->format('Y-m-d H:i:s') . "). ";
                            continue; // Пропускаем запись, если прием заказов уже закрыт на эту неделю.
                        } else {
                            $explanation .= "Запись с диапазоном {$row['delivery_time_range']} — прием заказов для этой недели открыт (окончание: " . $acceptanceEndDate->format('Y-m-d H:i:s') . "). ";
                        }
                    }
                    $applicableRows[] = $row;
                }
            }

            if (empty($applicableRows)) {
                $explanation .= "Нет доступных доставок для этого дня.";
                $schedule[$day->format('d.m')] = [
                    'available' => false,
                    'time_slots' => [],
                    'delivery_cost' => null,
                    'required_weight' => null,
                    'notes' => 'Доставка не осуществляется в этот день.',
                    'explanation' => $explanation,
                    'error' => ['Нет доступных доставок для этого дня.'],
                    'have_error' => 1,
                    'special_price' => 0,
                    'day_format'=>$dayOfWeekFormated
                ];

                continue;
            }

            $allTimeSlots = [];
            $rowExplanations = [];
            // Генерируем временные интервалы для каждой применимой записи
            foreach ($applicableRows as $row) {
                list($startTimeStr, $endTimeStr) = explode('-', $row['delivery_time_range']);
                $startTime = DateTime::createFromFormat('Y-m-d H:i', $day->format('Y-m-d') . ' ' . trim($startTimeStr));
                $endTime = DateTime::createFromFormat('Y-m-d H:i', $day->format('Y-m-d') . ' ' . trim($endTimeStr));
                $timeSlots = [];
                $slotStart = clone $startTime;
                while ($slotStart < $endTime) {
                    // Для сегодняшнего дня пропускаем интервалы, начало которых уже прошло
                    if ($day->format('Y-m-d') == $currentDateTime->format('Y-m-d') && $slotStart < $currentDateTime) {
                        $slotStart->modify('+1 hour');
                        continue;
                    }
                    $slotEnd = clone $slotStart;
                    $slotEnd->modify('+1 hour');
                    if ($slotEnd > $endTime) {
                        $slotEnd = $endTime;
                    }
                    $timeSlots[] = $slotStart->format('H:i') . '-' . $slotEnd->format('H:i');
                    $slotStart->modify('+1 hour');
                }
                $rowExplanations[] = "Запись с диапазоном {$row['delivery_time_range']}: " . implode(", ", $timeSlots);
                $allTimeSlots = array_merge($allTimeSlots, $timeSlots);
            }

            // === Новый блок: Ограничение доставки для сегодняшнего дня ===
            // Если доставка на сегодня и текущее время меньше 14:00, оставляем только слоты, начинающиеся с 18:00 и позже
            if ($day->format('Y-m-d') == $currentDateTime->format('Y-m-d') && $currentDateTime->format('H:i') < '13:00') {
                $eveningSlots = [];
                foreach ($allTimeSlots as $slot) {
                    $parts = explode('-', $slot);
                    $slotStart = $parts[0];
                    if ($slotStart >= '18:00') {
                        $eveningSlots[] = $slot;
                    }
                }
                $explanation .= " Так как время до 14:00, доступны только вечерние слоты. ";
                $allTimeSlots = $eveningSlots;
            }
            // === Конец нового блока ===

            // Если для сегодняшнего дня все интервалы уже прошли, выводим день с ошибкой
            if ($day->format('Y-m-d') == $currentDateTime->format('Y-m-d') && empty($allTimeSlots)) {
                $explanation .= " Для сегодняшнего дня все интервалы уже прошли.";
                $schedule[$day->format('d.m')] = [
                    'available' => false,
                    'time_slots' => [],
                    'delivery_cost' => null,
                    'required_weight' => null,
                    'notes' => 'Для сегодняшнего дня доставка недоступна.',
                    'explanation' => $explanation,
                    'error' => ['Для сегодняшнего дня все интервалы уже прошли.'],
                    'have_error' => 1,
                    'special_price' => 0,
                    'day_format'=>$dayOfWeekFormated
                ];

                continue;
            }

            $allTimeSlots = array_unique($allTimeSlots);
            sort($allTimeSlots);
            $explanation .= " Найдено " . count($applicableRows) . " применимых доставок. " . implode(" | ", $rowExplanations);

            // Определяем тарифы – для простоты используем тариф из первой применимой записи.
            $base = $applicableRows[0];

            // Проверка special_day: если поле special_day задано (и не равно "[NULL]") и содержит либо конкретную дату,
            // либо номера дней недели, совпадающие с текущим днем, применяем специальные тарифы.
            $isSpecial = false;
            if (isset($base['special_day']) && $base['special_day'] && $base['special_day'] !== '[NULL]') {
                $specialDays = array_map('trim', explode(',', $base['special_day']));
                if (in_array($day->format('Y-m-d'), $specialDays)) {
                    $isSpecial = true;
                    $explanation .= " Дата " . $day->format('Y-m-d') . " является специальной (по дате). ";
                } else {
                    $specialDaysNumeric = array_map('intval', $specialDays);
                    if (in_array($dayOfWeek, $specialDaysNumeric)) {
                        $isSpecial = true;
                        $explanation .= " День недели {$dayOfWeek} является специальным (по дню недели). ";
                    }
                }
            }

            if ($isSpecial) {
                $deliveryCost = is_numeric($base['special_day_cost']) ? floatval($base['special_day_cost']) : null;
                $requiredWeight = is_numeric($base['special_day_weight']) ? floatval($base['special_day_weight']) : $effectiveWeight;
                $notes = "Применены специальные тарифы.";
                $explanation .= " Применены специальные тарифы: стоимость = {$deliveryCost}, минимальный вес = {$requiredWeight}. ";
            } else {
                $deliveryCost = is_numeric($base['no_special_day_cost']) ? floatval($base['no_special_day_cost']) : null;
                // Если тариф не специальный, то берем min_weight, если он задан, иначе effectiveWeight
                if (isset($base['min_weight']) && is_numeric($base['min_weight']) && floatval($base['min_weight']) > 0) {
                    $requiredWeight = floatval($base['min_weight']);
                } else {
                    $requiredWeight = $effectiveWeight;
                }
                $notes = "Использованы тарифы из первой применимой записи.";
            }

            // Проверяем минимальную стоимость, если она задана в записи
            $minCostRecord = (isset($base['min_cost']) && is_numeric($base['min_cost'])) ? floatval($base['min_cost']) : 0;

            // Формируем массив ошибок (но выводим день, даже если есть ошибки)
            if ($weight < $requiredWeight) {
                $errors[] = "Вес заказа ({$weight} кг) меньше минимально требуемого ({$requiredWeight} кг).";
            }
            if ($minCostRecord > 0 && $cost < $minCostRecord) {
                $errors[] = "Стоимость заказа ({$cost} ₽) меньше минимально необходимой ({$minCostRecord} ₽).";
            }

            // Бесплатная доставка – если сумма заказа превышает заданный порог.
            $freeDelivery = is_numeric($base['free_delivery']) ? floatval($base['free_delivery']) : 0;
            if ($freeDelivery > 0 && $effectiveCost >= $freeDelivery) {
                $explanation .= " Сумма заказа ({$effectiveCost}) превышает порог бесплатной доставки ({$freeDelivery}). ";
                $deliveryCost = 0;
            }

            $schedule[$day->format('d.m')] = [
                'available'       => true,
                'time_slots'      => $allTimeSlots,
                'delivery_cost'   => $deliveryCost,
                'required_weight' => $requiredWeight,
                'notes'           => $notes,
                'explanation'     => $explanation,
                'error'           => $errors,
                'have_error'      => !empty($errors) ? 1 : 0,
                'special_price'   => $isSpecial ? 'hot' : '',
                'day_format'      => $dayOfWeekFormated
            ];
            $availableCount++;
        }

        return $schedule;
    }
}
