<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Globe2, LockKeyhole, MapPin } from '@lucide/vue';
import type { Map as MapboxMap, Marker } from 'mapbox-gl';
import 'mapbox-gl/dist/mapbox-gl.css';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useAppearance } from '@/composables/useAppearance';
import { edit as editMapbox } from '@/routes/settings/mapbox';

type Visitor = {
    id: string;
    latitude: number;
    longitude: number;
    country: string | null;
    lastSeenAt: string;
    active: boolean;
    avatar: number;
};

const props = defineProps<{
    accessToken: string | null;
    canManage: boolean;
    timezone: string;
    totalVisitors: number;
    locatedVisitors: number;
    visitors: Visitor[];
}>();

const mapElement = ref<HTMLElement | null>(null);
const mapError = ref(false);
const { resolvedAppearance } = useAppearance();
const activeVisitors = computed(
    () => props.visitors.filter((visitor) => visitor.active).length,
);
let map: MapboxMap | null = null;
let markers: Marker[] = [];
let isStarting = false;
let isReady = false;

function formatLastSeen(value: string): string {
    return new Intl.DateTimeFormat(undefined, {
        hour: 'numeric',
        minute: '2-digit',
        timeZone: props.timezone,
    }).format(new Date(value));
}

function markerPosition(visitor: Visitor): [number, number] {
    const longitudeJitter =
        (Number.parseInt(visitor.id.slice(0, 2), 16) / 255 - 0.5) * 0.18;
    const latitudeJitter =
        (Number.parseInt(visitor.id.slice(2, 4), 16) / 255 - 0.5) * 0.18;

    return [
        ((visitor.longitude + longitudeJitter + 540) % 360) - 180,
        Math.max(-85, Math.min(85, visitor.latitude + latitudeJitter)),
    ];
}

function clearMarkers(): void {
    markers.forEach((marker) => marker.remove());
    markers = [];
}

async function syncMarkers(): Promise<void> {
    if (!map || !isReady) {
        return;
    }

    const mapboxgl = (await import('mapbox-gl')).default;
    clearMarkers();
    markers = props.visitors.map((visitor) => {
        const avatar = document.createElement('button');
        avatar.type = 'button';
        avatar.title = visitor.active
            ? 'Active visitor'
            : `Visitor last seen at ${formatLastSeen(visitor.lastSeenAt)}`;
        avatar.setAttribute('aria-label', avatar.title);
        avatar.style.cssText = [
            'width:30px',
            'height:30px',
            'border-radius:9999px',
            `border:3px solid ${visitor.active ? '#10b981' : '#94a3b8'}`,
            'background-color:#e2e8f0',
            `background-image:url("https://api.dicebear.com/10.x/thumbs/svg?seed=peekchimp-${visitor.avatar}")`,
            'background-position:center',
            'background-size:cover',
            'box-shadow:0 2px 8px rgba(15,23,42,.28)',
            'cursor:pointer',
        ].join(';');

        const popupContent = document.createElement('div');
        const status = document.createElement('strong');
        const detail = document.createElement('div');
        status.textContent = visitor.active ? 'Active now' : 'Visited today';
        detail.textContent = `${visitor.country ?? 'Unknown location'} · ${formatLastSeen(visitor.lastSeenAt)}`;
        popupContent.append(status, detail);

        return new mapboxgl.Marker({ element: avatar, anchor: 'center' })
            .setLngLat(markerPosition(visitor))
            .setPopup(
                new mapboxgl.Popup({
                    offset: 20,
                    closeButton: false,
                }).setDOMContent(popupContent),
            )
            .addTo(map!);
    });
}

async function startMap(): Promise<void> {
    if (
        map ||
        isStarting ||
        !props.accessToken ||
        props.visitors.length === 0
    ) {
        return;
    }

    isStarting = true;
    mapError.value = false;

    try {
        await nextTick();

        if (!mapElement.value) {
            return;
        }

        const initialVisitor = props.visitors[0];

        if (!initialVisitor) {
            return;
        }

        const mapboxgl = (await import('mapbox-gl')).default;
        map = new mapboxgl.Map({
            accessToken: props.accessToken,
            container: mapElement.value,
            style: 'mapbox://styles/mapbox/standard',
            projection: 'globe',
            center: markerPosition(initialVisitor),
            zoom: 0.5,
            config: {
                basemap: {
                    lightPreset:
                        resolvedAppearance.value === 'dark' ? 'night' : 'day',
                },
            },
        });
        map.scrollZoom.disable();
        map.addControl(
            new mapboxgl.NavigationControl({ showCompass: false }),
            'top-right',
        );
        map.once('load', () => {
            isReady = true;
            void syncMarkers();
        });
        map.on('error', ({ error }) => {
            if (!isReady || /403|access token|scope/i.test(error.message)) {
                mapError.value = true;
            }
        });
    } catch {
        mapError.value = true;
        map?.remove();
        map = null;
    } finally {
        isStarting = false;
    }
}

watch(
    () => props.visitors,
    () => {
        if (map) {
            void syncMarkers();
        } else {
            void startMap();
        }
    },
    { deep: true },
);

watch(resolvedAppearance, (appearance) => {
    map?.setConfigProperty(
        'basemap',
        'lightPreset',
        appearance === 'dark' ? 'night' : 'day',
    );
});

onMounted(() => void startMap());
onBeforeUnmount(() => {
    clearMarkers();
    map?.remove();
    map = null;
});
</script>

<template>
    <Card class="h-full gap-0 overflow-hidden p-1">
        <div
            class="flex h-full min-h-[386px] flex-col rounded-xl bg-background/70 p-4 sm:p-5"
        >
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                    <span
                        class="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-card text-muted-foreground"
                        aria-hidden="true"
                    >
                        <Globe2 class="size-4" />
                    </span>
                    <div>
                        <h2 class="font-medium">Visitors around the globe</h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Today in {{ timezone }} · {{ locatedVisitors }} of
                            {{ totalVisitors }} located
                        </p>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-3 text-[11px]">
                    <span class="flex items-center gap-1.5">
                        <i class="size-2 rounded-full bg-emerald-500" />
                        {{ activeVisitors }} active
                    </span>
                    <span class="hidden items-center gap-1.5 sm:flex">
                        <i class="size-2 rounded-full bg-slate-400" /> Today
                    </span>
                </div>
            </div>

            <div
                class="relative mt-5 flex min-h-64 flex-1 overflow-hidden rounded-xl border border-border/80 bg-muted/40"
            >
                <div
                    v-if="accessToken && visitors.length > 0"
                    ref="mapElement"
                    class="absolute inset-0"
                    aria-label="Map of visitors seen today"
                />

                <div
                    v-if="!accessToken || mapError"
                    class="absolute inset-0 z-10 flex flex-col items-center justify-center bg-background/88 px-6 text-center backdrop-blur-sm"
                    role="status"
                >
                    <span
                        class="mb-3 flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground"
                        aria-hidden="true"
                    >
                        <LockKeyhole class="size-5" />
                    </span>
                    <p class="font-medium">
                        {{
                            mapError
                                ? 'Mapbox could not load'
                                : 'Visitor map locked'
                        }}
                    </p>
                    <p class="mt-1 max-w-xs text-sm text-muted-foreground">
                        {{
                            canManage
                                ? 'Add or replace the workspace Mapbox public token to show the globe.'
                                : 'Ask a workspace admin to add a Mapbox public token.'
                        }}
                    </p>
                    <Button v-if="canManage" as-child size="sm" class="mt-4">
                        <Link :href="editMapbox()">Open map settings</Link>
                    </Button>
                </div>

                <div
                    v-else-if="visitors.length === 0"
                    class="m-auto flex flex-col items-center px-6 text-center"
                    role="status"
                >
                    <MapPin class="mb-3 size-6 text-muted-foreground" />
                    <p class="font-medium">No located visitors yet today</p>
                    <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                        New visits appear here after their approximate city is
                        resolved.
                    </p>
                </div>
            </div>

            <p class="mt-3 text-[11px] text-muted-foreground">
                Approximate city only · IP geolocation by
                <a
                    href="https://db-ip.com/"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="underline-offset-4 hover:text-foreground hover:underline"
                    >DB-IP</a
                >
            </p>
        </div>
    </Card>
</template>
