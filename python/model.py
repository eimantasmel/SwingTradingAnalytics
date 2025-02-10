import numpy as np
from sklearn.ensemble import RandomForestRegressor

tickers_data = [
    {
        'ticker': 'BTC',
        'candlesticks': [
            [1.1, 1.2, 1.3, 1.0, 1000],  # Example candlesticks for BTC
            # More candlesticks for BTC...
        ],
        'crypto_market_candlesticks': [
            [1.5, 1.6, 1.7, 1.4, 2000],  # Example candlesticks for the general crypto market
            # More candlesticks for the crypto market...
        ]
    },
    {
        'ticker': 'ETH',
        'candlesticks': [
            [2.1, 2.2, 2.3, 2.0, 500],  # Example candlesticks for ETH
            # More candlesticks for ETH...
        ],
        'crypto_market_candlesticks': [
            [2.5, 2.6, 2.7, 2.4, 1500],  # Example candlesticks for the crypto market
            # More candlesticks for the crypto market...
        ]
    },
    # Add more tickers as needed...
]

# Process data to prepare training samples
def process_data(tickers_data):
    X = []  # Input features (200 candlesticks for each ticker + market data)
    y = []  # Output labels (highest price of next 10 candlesticks for the ticker)

    for ticker_data in tickers_data:
        ticker_candlesticks = ticker_data['candlesticks']
        market_candlesticks = ticker_data['crypto_market_candlesticks']

        # Ensure both candlestick arrays are the right length
        ticker_length = len(ticker_candlesticks)  # Should be 210
        market_length = len(market_candlesticks)  # Should be 200

        # We will create a sample from the first 200 candlesticks of the ticker
        if ticker_length >= 210 and market_length >= 200:
            # Create training samples by sliding over the candlestick data
            for i in range(ticker_length - 210):  # We need 200 candlesticks for input, and 10 for output
                ticker_input = ticker_candlesticks[i:i+200]  # 200 candlesticks for the ticker
                market_input = market_candlesticks[i:i+200]  # 200 candlesticks for market data
                
                # Flatten the input (combine the ticker and market data and flatten to 1D)
                combined_input = np.array(ticker_input + market_input).flatten()
                
                # Get the next 10 candlesticks' highest price for the ticker (target output)
                next_10_highest_prices = [candlestick[2] for candlestick in ticker_candlesticks[i+200:i+210]]
                next_10_highest_max = max(next_10_highest_prices)
                
                # Predict if the highest price will be at least 2x the last candlestick's price
                last_candlestick_price = ticker_candlesticks[i + 199][2]  # Get the highest price of the last candlestick
                if next_10_highest_max >= last_candlestick_price * 2:
                    label = 1  # 1 if price doubles or more
                else:
                    label = 0  # 0 otherwise

                X.append(combined_input)
                y.append(label)  # Predicting if price doubles or not

    return np.array(X), np.array(y)

# Train the model
def train_model(X, y):
    model = RandomForestRegressor(n_estimators=100, random_state=42)
    model.fit(X, y)
    return model

# Use the model to make predictions
def predict_future_candlesticks(model, new_input_data):
    prediction = model.predict([new_input_data])
    return prediction

# Save model to a file
def save_model(model, filename='candlestick_model.pkl'):
    with open(filename, 'wb') as file:
        pickle.dump(model, file)
    print(f"Model saved to {filename}")

# Load model from a file
def load_model(filename='candlestick_model.pkl'):
    with open(filename, 'rb') as file:
        model = pickle.load(file)
    print(f"Model loaded from {filename}")
    return model


# Example usage:
X, y = process_data(tickers_data)  # Process the data

# Check if the model is already saved
try:
    model = load_model()  # Try to load the model if it exists
except FileNotFoundError:
    print("Model not found, training...")
    model = train_model(X, y)  # Train model if not found
    save_model(model)  # Save the trained model


# Predict for a new sample (i.e., new ticker and market data)
new_input_data = X[0]  # A new input sample (this should be 200 candlesticks of ticker + market)
prediction = predict_future_candlesticks(model, new_input_data)
print(f"Prediction (0: not double, 1: double or more): {prediction[0]}")

