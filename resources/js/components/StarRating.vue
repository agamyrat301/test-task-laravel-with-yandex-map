<template>
    <div class="flex items-center gap-0.5">
        <svg
            v-for="i in 5"
            :key="i"
            class="w-4 h-4"
            :class="starClass(i)"
            viewBox="0 0 20 20"
            fill="currentColor"
        >
            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.967a1 1 0 00.95.69h4.173c.969 0 1.371 1.24.588 1.81l-3.38 2.456a1 1 0 00-.364 1.118l1.287 3.966c.3.921-.755 1.688-1.54 1.118l-3.38-2.455a1 1 0 00-1.175 0l-3.38 2.455c-.784.57-1.838-.197-1.54-1.118l1.287-3.966a1 1 0 00-.364-1.118L2.05 9.394c-.783-.57-.38-1.81.588-1.81h4.173a1 1 0 00.95-.69L9.05 2.927z"/>
        </svg>
    </div>
</template>

<script setup>
const props = defineProps({
    value: { type: Number, default: 0 },
    max: { type: Number, default: 5 },
})

function starClass(i) {
    return i <= Math.round(props.value)
        ? 'text-yellow-400'
        : 'text-gray-200'
}
</script>
