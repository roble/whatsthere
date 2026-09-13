import {
    BrainIcon,
    CircleHelpIcon,
    CogIcon,
    MapPinIcon,
    MapPinnedIcon,
    RouteIcon,
} from '@lucide/vue';
import {
    toMapView,
    type MapMarker,
    type MapView,
} from '@modules/chat/resources/js/map';
import type { Component } from 'vue';

/**
 * A streamed message part, as much of it as a thought needs.
 *
 * The AI SDK types every tool as its own `tool-<name>` variant, so a registry
 * keyed by part type cannot be given one union that fits them all. This is the
 * shared surface: everything below reads it defensively.
 */
export type ThoughtPart = {
    type: string;
    state?: string;
    text?: string;
    input?: Record<string, unknown>;
    output?: unknown;
    errorText?: string;
};

/** What renders inside a step, mapped to the chain-of-thought body components. */
export type ThoughtBody =
    | { kind: 'markdown'; text: string }
    | {
          kind: 'results';
          items: Array<{ label: string; marker: MapMarker }>;
      }
    | { kind: 'image'; src: string; caption?: string };

export type ThoughtKind = {
    icon: Component;
    /** Translation key, with `:name` placeholders filled from `params`. */
    label: string;
    /** Translation key once the step has finished. Falls back to `label`. */
    doneLabel?: string;
    /** Translation key when it finished without achieving anything. */
    failedLabel?: string;
    /**
     * Did the call actually do what it was for?
     *
     * A tool that answers in prose rather than erroring still finished, so
     * without this a lookup that found nothing reads as "Found ..." -- the one
     * thing it did not do.
     */
    succeeded?: (part: ThoughtPart) => boolean;
    params?: (part: ThoughtPart) => Record<string, string>;
    description?: (part: ThoughtPart) => string | undefined;
    body?: (part: ThoughtPart) => ThoughtBody | undefined;
};

/**
 * Every kind of thought the chain can show, keyed by the streamed part type.
 *
 * Adding one is a single entry here. A record rather than a list of matcher
 * functions because every part type the Vercel stream protocol can deliver is
 * a literal string -- `reasoning`, or `tool-<name>` -- so a key is already a
 * complete match, and the registry stays something you can read at a glance.
 *
 * A new *body* shape is the one change that costs two edits: a variant on
 * ThoughtBody above, and a branch in the step template in Index.vue.
 */
/**
 * How many a search turned up, as the step should word it.
 *
 * FindPlaces returns a selection rather than a complete inventory.
 */
function countOf(view: MapView | null): string {
    return String(view?.markers?.length ?? 0);
}

export const THOUGHT_KINDS: Record<string, ThoughtKind> = {
    'tool-save_property_preferences': {
        icon: CircleHelpIcon,
        label: 'Preparing buying preferences',
        doneLabel: 'Preferences ready to review',
    },
    'tool-search_properties': {
        icon: MapPinnedIcon,
        label: 'Searching properties for sale',
        doneLabel: 'Found :count matching properties',
        params: (part) => ({ count: countOf(toMapView(part.output)) }),
        succeeded: (part) => toMapView(part.output) !== null,
    },
    reasoning: {
        icon: BrainIcon,
        label: 'Thinking',
        doneLabel: 'Thought it through',
        body: (part) => ({ kind: 'markdown', text: part.text ?? '' }),
    },

    'tool-show_on_map': {
        icon: MapPinIcon,
        label: 'Finding :place',
        doneLabel: 'Found :place',
        failedLabel: 'Could not find :place',
        // The map tools answer in prose when they come up empty, so a parsed
        // view is the only proof the call landed anywhere.
        succeeded: (part) => toMapView(part.output) !== null,
        params: (part) => ({ place: String(part.input?.place ?? '') }),
        description: (part) => toMapView(part.output)?.label,
    },

    'tool-find_places': {
        icon: MapPinnedIcon,
        label: 'Searching :area for :category',
        doneLabel: 'Showing :count :category in :area',
        failedLabel: 'Found no :category in :area',
        // The map tools answer in prose when they come up empty, so a parsed
        // view is the only proof the call landed anywhere.
        succeeded: (part) => toMapView(part.output) !== null,
        params: (part) => ({
            // The tool sends the plural back with its result; the raw key
            // is only the fallback for the moment before it answers.
            category:
                toMapView(part.output)?.category ??
                String(part.input?.category ?? '').replace(/_/g, ' '),
            area: String(part.input?.area ?? ''),
            count: countOf(toMapView(part.output)),
        }),
        description: (part) => toMapView(part.output)?.label,
        // Keep the marker with its label so the result can drive the map as
        // well as describe what the search found.
        body: (part) => {
            const found = toMapView(part.output)?.markers ?? [];

            return found.length
                ? {
                      kind: 'results',
                      items: found.map((marker) => ({
                          label: marker.name,
                          marker,
                      })),
                  }
                : undefined;
        },
    },

    'tool-save_itinerary': {
        icon: RouteIcon,
        label: 'Planning the day',
        doneLabel: 'Planned :count stops',
        failedLabel: 'Could not plan the day',
        // Stops that would not geocode are dropped, so a result can come back
        // well-formed and empty.
        succeeded: (part) => Boolean(toMapView(part.output)?.stops?.length),
        params: (part) => ({
            count: String(toMapView(part.output)?.stops?.length ?? 0),
        }),
        description: (part) => toMapView(part.output)?.label,
        // The same chips a search produces, so a stop is clickable straight
        // from the route of thought.
        body: (part) => {
            const stops = toMapView(part.output)?.stops ?? [];

            return stops.length
                ? {
                      kind: 'results',
                      items: stops.map((stop, index) => ({
                          label: `${index + 1}. ${stop.title}`,
                          marker: {
                              lat: stop.lat,
                              lon: stop.lon,
                              name: stop.title,
                          },
                      })),
                  }
                : undefined;
        },
    },

    'tool-interview_visitor': {
        icon: CircleHelpIcon,
        label: 'Ask a user question',
        doneLabel: 'Asked a user question',
    },
};

/**
 * The fallback for a tool with no entry above.
 *
 * A new backend tool then shows up as a plain step rather than disappearing,
 * which is the difference between a missing registry entry being obvious and
 * being invisible.
 */
export const UNKNOWN_TOOL: ThoughtKind = {
    icon: CogIcon,
    label: 'Working on :tool',
    doneLabel: 'Finished :tool',
};
