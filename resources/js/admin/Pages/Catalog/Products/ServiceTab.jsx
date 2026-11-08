import { useMemo } from 'react';
import {
    Checkbox,
    ChoiceCards,
    Field,
    Input,
    Select,
    Textarea,
    ui,
    cx,
} from '../../../ui';

function Section({ title, hint, children }) {
    return (
        <section className={ui.sectionCard}>
            {title ? (
                <div className={ui.sectionHead}>
                    <h2 className={ui.sectionTitle}>{title}</h2>
                    {hint ? <p className={ui.sectionHint}>{hint}</p> : null}
                </div>
            ) : null}
            {children}
        </section>
    );
}

const WEEKDAYS = [
    { value: 'mon', label: 'Monday' },
    { value: 'tue', label: 'Tuesday' },
    { value: 'wed', label: 'Wednesday' },
    { value: 'thu', label: 'Thursday' },
    { value: 'fri', label: 'Friday' },
    { value: 'sat', label: 'Saturday' },
    { value: 'sun', label: 'Sunday' },
];

export function defaultServiceConfig() {
    return {
        service_type: 'one_time',
        duration: '',
        duration_unit: 'hours',
        pricing_model: 'fixed',
        delivery_method: 'online',
        meeting_instructions: '',
        delivery_instructions: '',
        online_service_url: '',
        service_area: '',
        location_info: '',
        travel_fee: '',
        availability_config: {
            available_days: ['mon', 'tue', 'wed', 'thu', 'fri'],
            start_time: '09:00',
            end_time: '18:00',
            booking_duration: 60,
            buffer_time: 15,
            max_bookings: '',
            min_advance_hours: 24,
            max_advance_days: 30,
        },
    };
}

export function servicePayload(config) {
    const availability = { ...(config.availability_config || {}) };

    return {
        service: {
            ...config,
            duration: config.duration === '' ? null : config.duration,
            travel_fee: config.travel_fee === '' ? null : config.travel_fee,
            meeting_instructions: config.meeting_instructions === '' ? null : config.meeting_instructions,
            delivery_instructions: config.delivery_instructions === '' ? null : config.delivery_instructions,
            online_service_url: config.online_service_url === '' ? null : config.online_service_url,
            service_area: config.service_area === '' ? null : config.service_area,
            location_info: config.location_info === '' ? null : config.location_info,
            duration_unit: config.duration === '' ? null : config.duration_unit,
            availability_config: config.service_type === 'appointment' ? availability : null,
        },
    };
}

export default function ServiceTab({ config, onChange, errors = {} }) {
    const availability = useMemo(
        () => ({ ...defaultServiceConfig().availability_config, ...(config.availability_config || {}) }),
        [config.availability_config],
    );

    const setConfig = (patch) => onChange({ ...config, ...patch });
    const setAvailability = (patch) => setConfig({
        availability_config: { ...availability, ...patch },
    });

    const showDuration = ['hourly', 'appointment'].includes(config.service_type);
    const showAvailability = config.service_type === 'appointment';
    const showOnlineFields = config.delivery_method === 'online' || config.delivery_method === 'both';
    const showOnsiteFields = config.delivery_method === 'onsite' || config.delivery_method === 'both';

    const toggleDay = (day) => {
        const days = new Set(availability.available_days || []);
        if (days.has(day)) {
            days.delete(day);
        } else {
            days.add(day);
        }
        setAvailability({ available_days: [...days] });
    };

    return (
        <div className="space-y-4">
            <Section title="Service type">
                <ChoiceCards
                    name="service-type"
                    options={[
                        { value: 'one_time', label: 'One-time service', hint: 'Delivered once after purchase.' },
                        { value: 'recurring', label: 'Recurring service', hint: 'Use the Subscription tab for billing cycles.' },
                        { value: 'hourly', label: 'Hourly service', hint: 'Priced per hour with a defined duration.' },
                        { value: 'appointment', label: 'Appointment service', hint: 'Requires scheduling configuration.' },
                    ]}
                    value={config.service_type}
                    onChange={(value) => setConfig({ service_type: value })}
                />
            </Section>

            {showDuration ? (
                <Section title="Service duration">
                    <div className={ui.fieldGrid}>
                        <Field label="Duration" error={errors['service.duration']}>
                            <Input wide type="number" min="0" step="0.01" value={config.duration} onChange={(e) => setConfig({ duration: e.target.value })} />
                        </Field>
                        <Field label="Duration unit" error={errors['service.duration_unit']}>
                            <Select wide value={config.duration_unit || 'hours'} onChange={(e) => setConfig({ duration_unit: e.target.value })}>
                                <option value="minutes">Minutes</option>
                                <option value="hours">Hours</option>
                                <option value="days">Days</option>
                            </Select>
                        </Field>
                    </div>
                </Section>
            ) : null}

            <Section title="Pricing model" hint="Base price is configured on the Pricing tab. This defines how the service price is interpreted.">
                <ChoiceCards
                    name="service-pricing-model"
                    options={[
                        { value: 'fixed', label: 'Fixed price' },
                        { value: 'per_hour', label: 'Per hour' },
                        { value: 'per_day', label: 'Per day' },
                        { value: 'starting_from', label: 'Starting from' },
                    ]}
                    value={config.pricing_model}
                    onChange={(value) => setConfig({ pricing_model: value })}
                />
            </Section>

            <Section title="Service delivery">
                <ChoiceCards
                    name="service-delivery"
                    options={[
                        { value: 'online', label: 'Online' },
                        { value: 'onsite', label: 'On-site' },
                        { value: 'both', label: 'Both' },
                    ]}
                    value={config.delivery_method}
                    onChange={(value) => setConfig({ delivery_method: value })}
                />
                {showOnlineFields ? (
                    <div className="space-y-3 mt-4">
                        <Field label="Meeting instructions">
                            <Textarea rows="3" value={config.meeting_instructions} onChange={(e) => setConfig({ meeting_instructions: e.target.value })} />
                        </Field>
                        <Field label="Online service URL">
                            <Input wide type="url" value={config.online_service_url} onChange={(e) => setConfig({ online_service_url: e.target.value })} />
                        </Field>
                    </div>
                ) : null}
                {showOnsiteFields ? (
                    <div className="space-y-3 mt-4">
                        <Field label="Service area">
                            <Input wide value={config.service_area} onChange={(e) => setConfig({ service_area: e.target.value })} />
                        </Field>
                        <Field label="Location information">
                            <Textarea rows="3" value={config.location_info} onChange={(e) => setConfig({ location_info: e.target.value })} />
                        </Field>
                        <Field label="Travel fee" error={errors['service.travel_fee']}>
                            <Input wide type="number" min="0" step="0.01" value={config.travel_fee} onChange={(e) => setConfig({ travel_fee: e.target.value })} />
                        </Field>
                    </div>
                ) : null}
                <div className="mt-4">
                    <Field label="Delivery instructions">
                        <Textarea rows="3" value={config.delivery_instructions} onChange={(e) => setConfig({ delivery_instructions: e.target.value })} />
                    </Field>
                </div>
            </Section>

            {showAvailability ? (
                <Section title="Availability" hint="Appointments are validated against these rules when customers pick a booking date.">
                    <div className="space-y-4">
                        <div>
                            <p className={cx(ui.label, 'mb-2')}>Available days</p>
                            <div className="flex flex-wrap gap-3">
                                {WEEKDAYS.map((day) => (
                                    <Checkbox
                                        key={day.value}
                                        label={day.label}
                                        checked={(availability.available_days || []).includes(day.value)}
                                        onChange={() => toggleDay(day.value)}
                                    />
                                ))}
                            </div>
                            {errors['service.availability_config'] ? (
                                <p className="text-sm text-red-600 mt-2">{errors['service.availability_config']}</p>
                            ) : null}
                        </div>
                        <div className={ui.fieldGrid}>
                            <Field label="Start time">
                                <Input wide type="time" value={availability.start_time || ''} onChange={(e) => setAvailability({ start_time: e.target.value })} />
                            </Field>
                            <Field label="End time">
                                <Input wide type="time" value={availability.end_time || ''} onChange={(e) => setAvailability({ end_time: e.target.value })} />
                            </Field>
                            <Field label="Booking duration (minutes)">
                                <Input wide type="number" min="1" value={availability.booking_duration ?? ''} onChange={(e) => setAvailability({ booking_duration: e.target.value })} />
                            </Field>
                            <Field label="Buffer time (minutes)">
                                <Input wide type="number" min="0" value={availability.buffer_time ?? ''} onChange={(e) => setAvailability({ buffer_time: e.target.value })} />
                            </Field>
                            <Field label="Maximum bookings">
                                <Input wide type="number" min="1" value={availability.max_bookings ?? ''} onChange={(e) => setAvailability({ max_bookings: e.target.value })} />
                            </Field>
                            <Field label="Minimum advance booking (hours)">
                                <Input wide type="number" min="0" value={availability.min_advance_hours ?? ''} onChange={(e) => setAvailability({ min_advance_hours: e.target.value })} />
                            </Field>
                            <Field label="Maximum advance booking (days)">
                                <Input wide type="number" min="0" value={availability.max_advance_days ?? ''} onChange={(e) => setAvailability({ max_advance_days: e.target.value })} />
                            </Field>
                        </div>
                    </div>
                </Section>
            ) : null}

            <Section title="Customer requirements" hint="Use the Customizable tab for file uploads, notes, and project requirements at checkout.">
                <p className={ui.muted}>
                    Customer notes, file uploads, and project requirements should be configured through the existing Customizable tab.
                </p>
            </Section>
        </div>
    );
}
