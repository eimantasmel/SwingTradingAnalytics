// Import Chart.js
import Chart from 'chart.js/auto';

// Import specific financial chart controllers and elements
import { CandlestickController, CandlestickElement } from 'chartjs-chart-financial';
import { OhlcController, OhlcElement } from 'chartjs-chart-financial';

// Import Luxon and adapter
import 'chartjs-adapter-luxon';

// Register the financial chart controllers and elements
Chart.register(CandlestickController, CandlestickElement, OhlcController, OhlcElement);

// Expose Chart globally
window.Chart = Chart;

