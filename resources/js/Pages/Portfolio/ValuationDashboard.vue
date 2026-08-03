<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    valuationDate: { type: String, required: true },
    totalPlnGrosze: { type: Number, required: true },
    state: { type: String, required: true },
    error: { type: String, default: null },
    positions: { type: Array, required: true },
});

const isLoading = ref(false);
const displayState = computed(() => (isLoading.value ? 'loading' : props.state));
</script>

<template>
    <main aria-labelledby="portfolio-valuation-heading">
        <header>
            <h1 id="portfolio-valuation-heading">Portfolio valuation</h1>
            <p>Valuation date: <time :datetime="valuationDate">{{ valuationDate }}</time></p>
        </header>

        <section aria-labelledby="portfolio-total-heading">
            <h2 id="portfolio-total-heading">Total PLN value</h2>
            <output aria-label="Total portfolio value in PLN grosze">{{ totalPlnGrosze }} PLN grosze</output>
        </section>

        <p v-if="displayState === 'loading'" role="status">Loading portfolio valuation…</p>
        <p v-else-if="error" role="alert">{{ error }}</p>
        <p v-else-if="displayState === 'empty'" role="status">No positions are available for this valuation date.</p>

        <section v-else aria-labelledby="positions-heading">
            <h2 id="positions-heading">Positions</h2>
            <table>
                <caption>Position valuation details</caption>
                <thead>
                    <tr>
                        <th scope="col">Instrument</th>
                        <th scope="col">Quantity</th>
                        <th scope="col">Source price</th>
                        <th scope="col">FX status</th>
                        <th scope="col">PLN grosze</th>
                        <th scope="col">Diagnostics</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="position in positions" :key="position.instrument">
                        <th scope="row">{{ position.instrument }}</th>
                        <td>{{ position.quantity }}</td>
                        <td>
                            <template v-if="position.sourcePrice.amount !== null">
                                {{ position.sourcePrice.amount }} {{ position.sourcePrice.currency }}
                            </template>
                            <span v-else>Unavailable</span>
                        </td>
                        <td>{{ position.fx.status }}</td>
                        <td>
                            <template v-if="position.plnGrosze !== null">{{ position.plnGrosze }}</template>
                            <span v-else>Unavailable</span>
                        </td>
                        <td>
                            <ul v-if="position.diagnostics.length">
                                <li v-for="diagnostic in position.diagnostics" :key="diagnostic">{{ diagnostic }}</li>
                            </ul>
                            <span v-else>None</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </main>
</template>
