# Swing Trading System with Monte Carlo Simulation

This swing trading system is built to validate trading strategies listed in the strategy directory, evaluate their effectiveness, and identify profitable trading opportunities. By integrating selected strategies defined in the ```services.yaml``` file, it provides precise recommendations on what to trade and when to trade. Additionally, the system analyzes the NASDAQ-2000 index to detect major uptrends and downtrends, offering insights into market movements in terms of compound annual growth rate (CAGR).

## What is Monte Carlo Simulation?

Monte Carlo simulation is a statistical method used to model and evaluate systems influenced by uncertainty. By running numerous simulations with random input values, it provides a range of potential outcomes along with their probabilities. Widely used in finance, engineering, and project management, it helps in assessing risks, forecasting results, and supporting data-driven decision-making. Each iteration produces unique results due to the inherent randomness, creating a comprehensive probability distribution of possible outcomes.

## Features

- **Strategy Validation**: Validates the performance of trading systems listed in the strategy directory and analyzes their effectiveness.  
- **Trading Recommendations**: Suggests optimal trading opportunities, detailing **what to trade** and **when to trade** based on the strategies specified in `services.yaml`.  
- **NASDAQ-2000 Index Analysis**: Analyzes market trends to detect major **uptrends** and **downtrends**, providing insights using **CAGR (Compound Annual Growth Rate)**.



## Requirements

To run this project, you will need:

- PHP 8.x
- Symfony 6+

### Before running commands don't forget to update or upload your cookies from yahoo into the cookies directory
![Cookies image](https://s3.amazonaws.com/i.snag.gy/v6crYR.jpg)
![Cookies image](https://s3.amazonaws.com/i.snag.gy/pM8PKG.jpg)


### Important Note on Web Scraping
This project relies on web scraping for data extraction from APIs, which may require updates to services like `YahooService`
![Web Scraping Notice](https://s3.amazonaws.com/i.snag.gy/3VIF1U.jpg)

### Command Guide

Use the following commands in the terminal to set up and execute the investment analysis:

```bash
# This command will fetch from yahoo finance. On the fetch command file you can select the starting year. But i recommend leave everything as it is.
php bin/console app:fetch-data

# Updates data if you in update command file  OLDER_DATE_START variable will assign to the most recent year. Because then the system will update that data to the most current much faster. Additionaly you can change OLDER_DATE_START variable to the 2012 and then the system will add older information of candlesticks but it will not update to the most current date because then request would take too long.
php bin/console app:update-data

# Runs montecarlo simulation. Before running this command select acceptable for you startegy in the services.yaml file.
php bin/console app:montecarlo-simulation

# As you guess it depends what strategy are you selected on services.yaml file and then find acceptable trades for you. One thing to mention if you will want to apply this to the real world example then I'm recommend to rund this command before nasdaq market closes because most of the strategies focuses on close prices as entry point.
php bin/console app:find-trades

# In Nasdaq2000AnalysisCommand file you can select interval and trend direction in order to find out at which dates market performed unsually. You can get top rated dates interval by trend affectiveness. Look at the visuals.
php bin/console app:nasdaq-2000-analysis

```

## Results and Visuals

#### Montecarlo simulation results:
![Montecarlo simulation](https://s3.amazonaws.com/i.snag.gy/DFM915.jpg)
#### Finds trades for specific strategy:
![Montecarlo simulation](https://s3.amazonaws.com/i.snag.gy/pSzNr6.jpg)
#### Nasdaq 2000 index analysis:
![Montecarlo simulation](https://s3.amazonaws.com/i.snag.gy/pSzNr6.jpg)
#### Right here you will update your strategy on services.yaml file:
![Montecarlo simulation](https://s3.amazonaws.com/i.snag.gy/pSzNr6.jpg)